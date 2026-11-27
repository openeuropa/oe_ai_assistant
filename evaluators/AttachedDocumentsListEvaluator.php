<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the attached-documents listing tracks attach/remove calls.
 */
final class AttachedDocumentsListEvaluator extends EditorialSessionEvaluatorBase {

  /**
   * Where the fixture files are written.
   *
   * Their own directory, away from the dated one real uploads go to: a run
   * that dies before its cleanup leaves its files behind.
   */
  private const string FILE_DIRECTORY = 'private://ai-context-documents/evaluation';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
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
    $question = 'list all context documents';

    try {
      $firstId = $this->attachDocument($session, $datasetItem['first']);
      $listsFirstDocument = $this->ask($sessionId, $question)->finalAnswer();

      $this->attachDocument($session, $datasetItem['second']);
      $listsBothDocuments = $this->ask($sessionId, $question)->finalAnswer();

      \Drupal::service(ContextDocumentRepository::class)
        ->remove($this->session($sessionId), $firstId);
      $listsOnlySecondDocument = $this->ask($sessionId, $question)->finalAnswer();
    }
    finally {
      $this->deleteSession($sessionId, $owner);
    }

    return [$listsFirstDocument, $listsBothDocuments, $listsOnlySecondDocument];
  }

  /**
   * Checks which document names each checkpoint's answer lists.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    [$listsFirstDocument, $listsBothDocuments, $listsOnlySecondDocument] = $output;

    $first = $datasetItem['first']['title'];
    $second = $datasetItem['second']['title'];

    $this->assert(
      new AgentJudge($this->judge(), "The reply lists a document named '{$first}'."),
      $listsFirstDocument,
      'lists_first_document',
    );
    $this->assert(
      new AgentJudge($this->judge(), "The reply lists two documents, named '{$first}' and '{$second}'."),
      $listsBothDocuments,
      'lists_both_documents',
    );
    $this->assert(
      new AgentJudge($this->judge(), "The reply lists a document named '{$second}', and does not list one named '{$first}'."),
      $listsOnlySecondDocument,
      'lists_only_second_document',
    );
  }

  /**
   * Attaches one fixture document, with its extraction already done.
   *
   * DocumentRepositoryBase::describe() reads the listing straight off the
   * media and its file, so we build those two with the extract and summary
   * fields already set. A real extraction run would cost a Tika request and
   * a provider summary call per document, and no assertion here reads the
   * text they produce.
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
