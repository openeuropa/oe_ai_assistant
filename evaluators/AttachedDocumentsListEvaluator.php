<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
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
   * Where the fixture files are written.
   *
   * Their own directory, away from the dated one real uploads go to: a run
   * that dies before its cleanup leaves its files behind.
   */
  private const string FILE_DIRECTORY = 'private://ai-context-documents/evaluation';

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

    // prepareDirectory() takes the directory by reference, so it needs a
    // variable and refuses the constant itself.
    $directory = self::FILE_DIRECTORY;
    \Drupal::service('file_system')->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
  }

  /**
   * Returns the one pair of fixture documents this evaluator runs with.
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
   * @return array
   *   What the agent answered at each checkpoint, in the order they ran.
   */
  public function run(array $datasetItem): mixed {
    [$session, $owner] = $this->createSession();
    $sessionId = (int) $session->id();
    $repository = \Drupal::service(ContextDocumentRepository::class);
    $manager = \Drupal::service(NeuronAgentManagerInterface::class);

    try {
      $agent = $this->editorialAgent($manager, $sessionId);
      $agent->chat(new UserMessage('Hi.'));

      $firstId = $this->attachDocument($session, $datasetItem['first']);

      $agent = $this->editorialAgent($manager, $sessionId);
      $agent->chat(new UserMessage('list all context documents'));
      $listsFirstDocument = Trajectory::fromChatHistory($agent->getChatHistory())->finalAnswer();

      $this->attachDocument($session, $datasetItem['second']);

      $agent = $this->editorialAgent($manager, $sessionId);
      $agent->chat(new UserMessage('list all context documents'));
      $listsBothDocuments = Trajectory::fromChatHistory($agent->getChatHistory())->finalAnswer();

      $repository->remove($this->reloadSession($sessionId), $firstId);

      $agent = $this->editorialAgent($manager, $sessionId);
      $agent->chat(new UserMessage('list all context documents'));
      $listsOnlySecondDocument = Trajectory::fromChatHistory($agent->getChatHistory())->finalAnswer();
    }
    finally {
      // Nested so the throwaway user is deleted even when reloading or
      // deleting the session throws. With zend.assertions off the assert()
      // in reloadSession() is a no-op, so a session that is already gone
      // would fatal here on a NULL.
      try {
        $this->reloadSession($sessionId)->delete();
      }
      finally {
        $owner->delete();
      }
    }

    return [$listsFirstDocument, $listsBothDocuments, $listsOnlySecondDocument];
  }

  /**
   * Loads the session again, the way every HTTP request does.
   *
   * ContentCreationSession::brief() reads the document list once and then
   * caches it on the object. That never shows up over HTTP, because every
   * request loads its own session. If we held on to one object here, each
   * turn would keep seeing the empty list we read before the first attach.
   */
  private function reloadSession(int $id): AiEditorialSessionInterface {
    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->loadUnchanged($id);
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * Builds an editorial agent for one turn, on a freshly loaded session.
   *
   * An agent answers a question about the documents through the session
   * object it was built with, and that object caches its document list the
   * first time it is read. An agent held across turns would keep reporting
   * the list as it stood when it first answered, and miss a later attach or
   * removal. So every turn gets its own agent, which is also what
   * DraftingPlugin::chat() does per request, and the stored thread
   * carries the conversation from one turn to the next.
   */
  private function editorialAgent(NeuronAgentManagerInterface $manager, int $sessionId): Agent {
    return $manager->createAgent('editorial', ['session' => $this->reloadSession($sessionId)]);
  }

  /**
   * Checks which document names each checkpoint's answer lists.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    [$listsFirstDocument, $listsBothDocuments, $listsOnlySecondDocument] = $output;

    $first = $datasetItem['first']['title'];
    $second = $datasetItem['second']['title'];

    $this->assert(
      new AgentJudge($this->judge, "The reply lists a document named '{$first}'."),
      $listsFirstDocument,
      'Lists the first document once it is attached',
    );
    $this->assert(
      new AgentJudge($this->judge, "The reply lists two documents, named '{$first}' and '{$second}'."),
      $listsBothDocuments,
      'Lists both documents once the second is attached',
    );
    $this->assert(
      new AgentJudge($this->judge, "The reply lists a document named '{$second}', and does not list one named '{$first}'."),
      $listsOnlySecondDocument,
      'Lists only the second document once the first is removed',
    );
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
   * Attaches one fixture document, with its extraction already done.
   *
   * The evaluation only reads the attached-documents listing, and
   * DocumentRepositoryBase::describe() builds that from the media and its
   * file. So we create those two here instead of calling
   * ContextDocumentRepository::add() and extract(). A real extraction run
   * costs a Tika request and a provider summary call per document, and no
   * assertion reads the text they produce.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to attach the document to.
   * @param array $fixture
   *   The document to build, with a 'title' and a 'text' key.
   *
   * @return string
   *   The new document's ID.
   */
  private function attachDocument(AiEditorialSessionInterface $session, array $fixture): string {
    // One path per document, and setUp() prepared the directory they share.
    $uri = self::FILE_DIRECTORY . '/' . \uniqid('eval-') . '.txt';
    \file_put_contents($uri, $fixture['text']);

    $file = File::create([
      'uri' => $uri,
      'filename' => $fixture['title'] . '.txt',
      'uid' => $session->getOwnerId(),
      'status' => 1,
    ]);
    $file->save();

    $media = \Drupal::entityTypeManager()->getStorage('media')->create([
      'bundle' => 'ai_context_document',
      // The listing reports the media label as the document title, and the
      // judge scores the titles the agent names. Without a label of our
      // own, the media type's field map takes it from the file name and the
      // agent answers "Q3 Budget.txt".
      'name' => $fixture['title'],
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $file->id()],
      ContextDocumentRepository::SESSION_FIELD => ['target_id' => $session->id()],
    ]);
    $media->save();

    // The save above runs DocumentMediaHooks::scheduleExtraction(), which
    // puts a new document back to 'scheduled' and empties its extract and
    // summary. So we write the three fields in a second save. That save
    // replaces no file, so sourceFileChanged() returns FALSE and nothing
    // schedules the document again.
    $media->set(DocumentExtractionProcessorInterface::EXTRACT_FIELD, $fixture['text']);
    // Each fixture is one sentence, so the text works as its own summary.
    $media->set(DocumentExtractionProcessorInterface::SUMMARY_FIELD, $fixture['text']);
    $media->set(
      DocumentExtractionProcessorInterface::STATE_FIELD,
      DocumentExtractionProcessorInterface::STATE_DONE,
    );
    $media->save();

    return (string) $media->id();
  }

}
