<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\Judges\CorrectnessJudge;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the assistant answers what happened in the session it serves.
 *
 * The editorial agent answers such a question through
 * context_get_session_activity, which reads the activity record. Each
 * checkpoint asks one question right after the change it is about, so a
 * failure names the change the record lost.
 */
final class SessionActivityAnswerEvaluator extends BaseEvaluator {

  /**
   * The judge that scores each checkpoint's answer against its criteria.
   */
  private AgentInterface $judge;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    // AgentJudge asks the judge for a JudgeScoreOutput, so plain chat is not
    // enough: the provider has to support structured responses.
    $provider = \Drupal::service(ProviderFactoryInterface::class)->create('chat_with_structured_response', ['evaluation_judge']);
    $this->judge = Agent::make()->setAiProvider($provider);
  }

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'input' => [
          'tone' => 'Formal',
          'changed_tone' => 'Technical',
          'document' => [
            'title' => 'Press Release',
            'text' => 'The product launches on March 3rd in Brussels.',
          ],
        ],
        // One fact per checkpoint. A judge scores a reply that answers half
        // of a two-part question as half wrong, so the count of the tone
        // changes and the tone before them are asked apart. The uploaded
        // file carries a unique suffix, so an expectation names the start
        // of its name.
        'expected_output' => [
          'created' => 'The session uses the Formal tone.',
          'tone_change_count' => 'The tone of the session changed once.',
          'tone_before_change' => 'Before the change the tone was Formal.',
          'document_attached' => 'One document is attached, and its name starts with "Press Release".',
          'document_removed' => 'A document whose name starts with "Press Release" was attached, and then removed.',
        ],
      ],
    ]);
  }

  /**
   * Asks one question after each change the editor makes.
   *
   * @param array $datasetItem
   *   The item to run, carrying the tones and the document under 'input'.
   *
   * @return array
   *   The answer of each checkpoint, keyed as 'expected_output' is.
   */
  public function run(array $datasetItem): mixed {
    $input = $datasetItem['input'];
    [$session, $owner] = $this->createSession($input['tone']);
    $sessionId = (int) $session->id();
    $switcher = \Drupal::service('account_switcher');

    // Attaching a document saves a media that references the uploaded file,
    // and Drupal checks that the current user may reference it. On the
    // command line we start out as anonymous, so we run as the owner.
    $switcher->switchTo($owner);

    try {
      $answers = [
        'created' => $this->ask($sessionId, 'What tone is this session using?'),
      ];

      $this->setTone($sessionId, $input['changed_tone']);
      $answers['tone_change_count'] = $this->ask($sessionId, 'How many times has the tone changed in this session?');
      $answers['tone_before_change'] = $this->ask($sessionId, 'What was the tone of this session before the last change?');

      $documentId = $this->attachDocument($sessionId, $input['document']);
      $answers['document_attached'] = $this->ask($sessionId, 'Which documents are attached to this session?');

      \Drupal::service(ContextDocumentRepository::class)->remove($this->session($sessionId), $documentId);
      $answers['document_removed'] = $this->ask($sessionId, 'What happened to the documents of this session?');
    }
    finally {
      $switcher->switchBack();
      // Nested so the throwaway user goes even when deleting the session
      // throws.
      try {
        $this->session($sessionId)->delete();
      }
      finally {
        $owner->delete();
      }
    }

    return $answers;
  }

  /**
   * Scores each answer against what the record held when it was asked.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    foreach ($datasetItem['expected_output'] as $checkpoint => $expected) {
      // CorrectnessJudge carries the criteria: it scores the reply against
      // the expected answer for meaning and facts. A criterion written by
      // hand scores the wording as well, and marks a correct one-line
      // answer down for quoting the value it was asked about.
      $this->assert(
        new CorrectnessJudge(judge: $this->judge, expected: $expected, threshold: 0.7),
        $output[$checkpoint],
        $checkpoint,
      );
    }
  }

  /**
   * Asks the editorial agent one question and returns its answer.
   *
   * @param int $sessionId
   *   The session to ask about.
   * @param string $question
   *   The question to ask.
   *
   * @return string
   *   The answer the turn ended on.
   */
  private function ask(int $sessionId, string $question): string {
    $agent = \Drupal::service(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $this->session($sessionId)]);
    $agent->chat(new UserMessage($question));

    return Trajectory::fromChatHistory($agent->getChatHistory())->finalAnswer();
  }

  /**
   * Picks a tone, the way any writer of the field does.
   *
   * @param int $sessionId
   *   The session to change.
   * @param string $label
   *   The name of the tone term to pick.
   */
  private function setTone(int $sessionId, string $label): void {
    $session = $this->session($sessionId);
    $session->set('tone', $this->tone($label)->id());
    $session->save();
  }

  /**
   * Attaches one document and extracts its text.
   *
   * @param int $sessionId
   *   The session to attach the document to.
   * @param array $document
   *   The document to write to a temporary file, with a title and a text.
   *
   * @return string
   *   The id of the attached document.
   */
  private function attachDocument(int $sessionId, array $document): string {
    $path = tempnam(sys_get_temp_dir(), 'eval_doc_');
    file_put_contents($path, $document['text']);
    // Give every upload its own name. An earlier run leaves its file behind,
    // and core would store this one as "Press Release_0.txt", which the agent
    // then reports back.
    $filename = $document['title'] . '-' . uniqid() . '.txt';
    $upload = new InputStreamUploadedFile($filename, $filename, $path, @filesize($path));

    $repository = \Drupal::service(ContextDocumentRepository::class);
    $attached = $repository->add($this->session($sessionId), $upload);
    $repository->extract($this->session($sessionId), $attached['id']);

    return $attached['id'];
  }

  /**
   * Loads the session again, the way every request does.
   *
   * ContentCreationSession caches the tone, the template and the documents
   * on first read. Holding on to one object would show every later turn the
   * state we read before the first change.
   *
   * @param int $id
   *   The session to load.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session.
   */
  private function session(int $id): AiEditorialSessionInterface {
    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->loadUnchanged($id);
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * Loads the tone term the site publishes under a name.
   *
   * @param string $label
   *   The name of the term.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  private function tone(string $label): TermInterface {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'oe_ai_tone', 'name' => $label]);
    $term = reset($terms);
    if (!$term instanceof TermInterface) {
      throw new \RuntimeException(sprintf('The site publishes no "%s" tone.', $label));
    }

    return $term;
  }

  /**
   * Creates a throwaway session owned by a throwaway user.
   *
   * @param string $tone
   *   The name of the tone term the session starts with.
   *
   * @return array
   *   The new session, then the user that owns it.
   */
  private function createSession(string $tone): array {
    $user = User::create([
      'name' => 'eval-' . uniqid(),
      'mail' => 'eval-' . uniqid() . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->create([
      'type' => 'content_creation',
      'uid' => $user->id(),
      'content_type' => 'oe_news',
      'tone' => $this->tone($tone)->id(),
    ]);
    $session->save();

    return [$session, $user];
  }

}
