<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\user\Entity\User;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the attached-documents listing tracks attach/remove calls.
 */
final class AttachedDocumentsListEvaluator extends BaseEvaluator {

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
   * Returns the one scripted document pair this evaluator replays.
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'first' => [
          'title' => 'Q3 Budget',
          'text' => 'The Q3 marketing budget is confidential: EUR 420,000 total spend.',
        ],
        'second' => [
          'title' => 'Press Release',
          'text' => 'The product launches on March 3rd in Brussels.',
        ],
      ],
    ]);
  }

  /**
   * Attaches, lists, attaches again, lists, removes, lists.
   *
   * Three checkpoints, each a fresh "list all context documents" turn:
   * right after the first document is attached, right after the second is
   * attached too, and right after the first is removed again.
   *
   * @return array<string, string>
   *   The final answer of each checkpoint's turn, keyed 'after_first_attached',
   *   'after_second_attached' and 'after_first_removed'.
   */
  public function run(array $datasetItem): mixed {
    [$session, $owner] = $this->createSession();
    $sessionId = (int) $session->id();
    $repository = \Drupal::service(ContextDocumentRepository::class);
    $manager = \Drupal::service(NeuronAgentManagerInterface::class);
    $switcher = \Drupal::service('account_switcher');

    // Attaching a document saves a media that references the file we just
    // uploaded, and Drupal validates that the current user may reference it.
    // Over HTTP that user is the editor who uploaded the file; on the command
    // line we start out as anonymous, so we run as the session owner instead.
    $switcher->switchTo($owner);

    try {
      $this->editorialAgent($manager, $this->reloadSession($sessionId))->chat(new UserMessage('Hi.'));

      $firstId = $this->attachDocument($repository, $this->reloadSession($sessionId), $datasetItem['first']);
      $afterFirst = $this->listContextDocuments($manager, $this->reloadSession($sessionId));

      $this->attachDocument($repository, $this->reloadSession($sessionId), $datasetItem['second']);
      $afterSecond = $this->listContextDocuments($manager, $this->reloadSession($sessionId));

      $repository->remove($this->reloadSession($sessionId), $firstId);
      $afterRemoval = $this->listContextDocuments($manager, $this->reloadSession($sessionId));
    }
    finally {
      $switcher->switchBack();
      // Nested, so a failure reloading or deleting the session (assert() is
      // a no-op once zend.assertions is off, so loadUnchanged() returning
      // NULL would otherwise fatal here) never leaves the throwaway user
      // behind.
      try {
        $this->reloadSession($sessionId)->delete();
      }
      finally {
        $owner->delete();
      }
    }

    return [
      'after_first_attached' => $afterFirst,
      'after_second_attached' => $afterSecond,
      'after_first_removed' => $afterRemoval,
    ];
  }

  /**
   * Loads the session again, the way every HTTP request does.
   *
   * ContentCreationSession::brief() reads the document list once and then
   * keeps it on the object. No HTTP request notices, since each one loads its
   * own session. Holding on to a single object here would pin every turn to
   * the empty document list we read before the first document was attached.
   */
  private function reloadSession(int $id): AiEditorialSessionInterface {
    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->loadUnchanged($id);
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * Asks the agent to list context documents, returns just that turn's answer.
   */
  private function listContextDocuments(NeuronAgentManagerInterface $manager, AiEditorialSessionInterface $session): string {
    $agent = $this->editorialAgent($manager, $session);
    $agent->chat(new UserMessage('list all context documents'));

    return Trajectory::fromChatHistory($agent->getChatHistory())->finalAnswer();
  }

  /**
   * Judges each checkpoint's answer for which document names it lists.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    assert(is_array($output));

    $first = $datasetItem['first']['title'];
    $second = $datasetItem['second']['title'];

    $this->assert(
      new AgentJudge($this->judge, "The reply lists a document named '{$first}'."),
      $output['after_first_attached'],
      'after_first_attached'
    );
    $this->assert(
      new AgentJudge($this->judge, "The reply lists two documents, named '{$first}' and '{$second}'."),
      $output['after_second_attached'],
      'after_second_attached'
    );
    $this->assert(
      new AgentJudge($this->judge, "The reply lists a document named '{$second}', and does not list one named '{$first}'."),
      $output['after_first_removed'],
      'after_first_removed'
    );
  }

  /**
   * Builds an editorial agent on the session's persisted thread.
   *
   * Every turn gets its own agent, the way EditorialWorkflow::nodes() builds
   * one per request. What carries the conversation over is the stored thread,
   * not the agent object, so a new agent picks up where the last turn left
   * off.
   */
  private function editorialAgent(NeuronAgentManagerInterface $manager, AiEditorialSessionInterface $session): Agent {
    return $manager->createAgent('editorial', ['session' => $session]);
  }

  /**
   * Creates a throwaway session owned by a throwaway user.
   *
   * @return array
   *   The new session, then the user that owns it.
   */
  private function createSession(): array {
    $user = User::create([
      'name' => 'eval-' . \uniqid(),
      'mail' => 'eval-' . \uniqid() . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->create([
      'type' => 'content_creation',
      'uid' => $user->id(),
      'content_type' => 'oe_news',
    ]);
    $session->save();

    return [$session, $user];
  }

  /**
   * Uploads one fixture document and runs its extraction synchronously.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository $repository
   *   The repository to attach the document through.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to attach the document to.
   * @param array $fixture
   *   The document to write to a temp file and attach, with a 'title' and a
   *   'text' key.
   *
   * @return string
   *   The new document's ID.
   */
  private function attachDocument(ContextDocumentRepository $repository, AiEditorialSessionInterface $session, array $fixture): string {
    $path = \tempnam(\sys_get_temp_dir(), 'eval_doc_');
    \file_put_contents($path, $fixture['text']);
    // Give every attach its own filename. Earlier runs leave their uploads
    // behind, so with a fixed name core would store this one as
    // "Q3 Budget_0.txt" and the agent would report that name back to us.
    $filename = $fixture['title'] . '-' . \uniqid() . '.txt';

    $upload = new InputStreamUploadedFile($filename, $filename, $path, @\filesize($path));

    $document = $repository->add($session, $upload);
    $repository->extract($session, $document['id']);

    return $document['id'];
  }

}
