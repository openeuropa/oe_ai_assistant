<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that amending a draft does not recall a document the editor removed.
 */
final class AmendDraftRemovedDocumentEvaluator extends EditorialSessionEvaluatorBase {

  use EvaluationDocumentsTrait;

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'input' => [
          'removed' => 'Fishing quotas',
          'kept' => 'Rail signalling',
          'brief' => 'Draft a news item from the material attached to this session, and go'
          . ' ahead with whatever context you have.',
          'amendment' => 'Polish the whole thing, make it read better throughout.',
        ],
        'expected_output' => [
          'amendment_keeps_the_document_that_is_left' => 'The text is still about European rail signalling.',
          'amendment_omits_the_removed_document' => 'The text says nothing anywhere about fishing, fisheries, fish stocks or fishing quotas.',
        ],
      ],
    ]);
  }

  /**
   * Asks about both documents, removes one, drafts, then amends the draft.
   *
   * @param array $datasetItem
   *   The item to run, carrying the documents, the brief and the amendment.
   *
   * @return string
   *   The text of the draft the amendment produced.
   */
  public function run(array $datasetItem): mixed {
    $input = $datasetItem['input'];
    [$session, $owner] = $this->createSession(template: 'news_with_paragraphs');
    $sessionId = (int) $session->id();

    try {
      $files = $this->fixtureFiles();
      $removedId = $this->attachFixtureDocument(
        $this->session($sessionId),
        $input['removed'],
        $files[$input['removed']],
      );
      $this->attachFixtureDocument(
        $this->session($sessionId),
        $input['kept'],
        $files[$input['kept']],
      );

      $this->ask(
        $sessionId,
        'Which documents are attached to this session, and what does each one cover?',
      );

      \Drupal::service(ContextDocumentRepository::class)
        ->remove($this->session($sessionId), $removedId);

      $this->ask($sessionId, $input['brief']);

      $this->ask($sessionId, $input['amendment']);

      return $this->latestDraftText($sessionId);
    }
    finally {
      $this->deleteFixtureDocuments();
      $this->deleteSession($sessionId, $owner);
    }
  }

  /**
   * Checks the amendment covers the kept document and nothing of the other.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    $expected = $datasetItem['expected_output'];

    $this->assert(
      new AgentJudge($this->judge(), $expected['amendment_keeps_the_document_that_is_left']),
      $output,
      'amendment_keeps_the_document_that_is_left',
    );
    $this->assert(
      new AgentJudge($this->judge(), $expected['amendment_omits_the_removed_document']),
      $output,
      'amendment_omits_the_removed_document',
    );
  }

}
