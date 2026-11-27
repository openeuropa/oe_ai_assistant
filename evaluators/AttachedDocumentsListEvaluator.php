<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the attached-documents listing tracks attach and remove calls.
 */
final class AttachedDocumentsListEvaluator extends EditorialSessionEvaluatorBase {

  use EvaluationDocumentsTrait;

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'first' => 'Fishing quotas',
        'second' => 'Rail signalling',
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
   * @param array $datasetItem
   *   The item to run, naming the two fixtures to attach.
   *
   * @return array
   *   The turn of each checkpoint, in the order they ran.
   */
  public function run(array $datasetItem): mixed {
    [$session, $owner] = $this->createSession();
    $sessionId = (int) $session->id();
    $files = $this->fixtureFiles();
    $question = 'list all context documents';

    try {
      $firstId = $this->attachFixtureDocument(
        $this->session($sessionId),
        $datasetItem['first'],
        $files[$datasetItem['first']],
      );
      $turns = [$this->ask($sessionId, $question)];

      $this->attachFixtureDocument(
        $this->session($sessionId),
        $datasetItem['second'],
        $files[$datasetItem['second']],
      );
      $turns[] = $this->ask($sessionId, $question);

      \Drupal::service(ContextDocumentRepository::class)
        ->remove($this->session($sessionId), $firstId);
      $turns[] = $this->ask($sessionId, $question);
    }
    finally {
      $this->deleteFixtureDocuments();
      $this->deleteSession($sessionId, $owner);
    }

    return $turns;
  }

  /**
   * Checks that each checkpoint reads the session and lists its documents.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    [$listsFirstDocument, $listsBothDocuments, $listsOnlySecondDocument] = $output;

    $first = $datasetItem['first'];
    $second = $datasetItem['second'];

    // All three checkpoints ask the same question. A repeat of the first
    // answer sounds right at the third one, and names the documents of two
    // turns ago. Only the call to context_get_editorial_context tells a
    // read of the session apart from such a repeat.
    $this->assert(
      new ToolWasCalled('context_get_editorial_context'),
      $listsFirstDocument,
      'reads_the_session_after_the_first_attach',
    );
    $this->assert(
      new ToolWasCalled('context_get_editorial_context'),
      $listsBothDocuments,
      'reads_the_session_after_the_second_attach',
    );
    $this->assert(
      new ToolWasCalled('context_get_editorial_context'),
      $listsOnlySecondDocument,
      'reads_the_session_after_the_remove',
    );

    $this->assert(
      new AgentJudge($this->judge(), "The reply lists a document called '{$first}'."),
      $listsFirstDocument->finalAnswer(),
      'lists_first_document',
    );
    $this->assert(
      new AgentJudge($this->judge(), "The reply lists two documents, called '{$first}' and '{$second}'."),
      $listsBothDocuments->finalAnswer(),
      'lists_both_documents',
    );
    $this->assert(
      new AgentJudge($this->judge(), "The reply lists a document called '{$second}', and lists none called '{$first}'."),
      $listsOnlySecondDocument->finalAnswer(),
      'lists_only_second_document',
    );
  }

}
