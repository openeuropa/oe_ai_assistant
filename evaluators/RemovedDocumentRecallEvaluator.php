<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that a document the editor removed stays out of later drafts.
 */
final class RemovedDocumentRecallEvaluator extends EditorialSessionEvaluatorBase {

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
          // The brief names no document on purpose, so whatever the draft
          // covers can only come from what the session still holds.
          'brief' => 'Draft a news item from the material attached to this session, and go'
          . ' ahead with whatever context you have.',
        ],
        'expected_output' => [
          'describes_both_documents' => 'The reply describes two attached documents, one about European Union fishing quotas and one about European rail signalling.',
          'drafts_the_document_that_is_left' => 'The draft is about European rail signalling.',
          'draft_omits_the_removed_document' => 'The draft says nothing anywhere about fishing, fisheries, fish stocks or fishing quotas.',
        ],
      ],
    ]);
  }

  /**
   * Reads both summaries, removes one document, then asks for a draft.
   *
   * @param array $datasetItem
   *   The item to run, carrying the documents and the brief.
   *
   * @return array
   *   The turn that read the summaries, and the text of the one draft.
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

      // The editorial agent's thread is the only place a removed document
      // can survive. This turn puts both summaries in the thread, so a
      // later draft can only have got the removed subject from there.
      $answers = [
        'describes_both_documents' => $this->ask(
          $sessionId,
          'Which documents are attached to this session, and what does each one cover?',
        ),
      ];

      \Drupal::service(ContextDocumentRepository::class)
        ->remove($this->session($sessionId), $removedId);

      $this->ask($sessionId, $input['brief']);
      $answers['draft'] = $this->latestDraftText($sessionId);
    }
    finally {
      $this->deleteFixtureDocuments();
      $this->deleteSession($sessionId, $owner);
    }

    return $answers;
  }

  /**
   * Checks the draft covers the kept document and nothing of the removed one.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    $expected = $datasetItem['expected_output'];

    $this->assert(
      new ToolWasCalled('context_get_editorial_context'),
      $output['describes_both_documents'],
      'editorial_context_tool_called',
    );
    $this->assert(
      new AgentJudge($this->judge(), $expected['describes_both_documents']),
      $output['describes_both_documents']->finalAnswer(),
      'describes_both_documents',
    );

    // A draft that read no document at all would pass
    // draft_omits_the_removed_document on its own. So we check as well that
    // the draft covers the document the session still holds.
    $this->assert(
      new AgentJudge($this->judge(), $expected['drafts_the_document_that_is_left']),
      $output['draft'],
      'drafts_the_document_that_is_left',
    );
    $this->assert(
      new AgentJudge($this->judge(), $expected['draft_omits_the_removed_document']),
      $output['draft'],
      'draft_omits_the_removed_document',
    );
  }

  /**
   * The text of every field of the draft the session stored last.
   *
   * @param int $sessionId
   *   The session that holds the draft.
   *
   * @return string
   *   Every scalar the draft holds, joined into one searchable string.
   */
  private function latestDraftText(int $sessionId): string {
    $session = $this->session($sessionId);
    assert($session instanceof ContentCreationSessionInterface);
    $drafts = $session->listDrafts();
    if ($drafts === []) {
      throw new \RuntimeException('The run stored no draft to read.');
    }

    $latest = end($drafts);
    $draft = $session->getDraftContent((int) $latest['version']);
    if ($draft === NULL) {
      throw new \RuntimeException(sprintf('Draft %s holds no content.', $latest['version']));
    }

    return $this->flatten($draft['fields']);
  }

  /**
   * Flattens a nested field map into one searchable string.
   *
   * @param array $values
   *   The values to flatten.
   *
   * @return string
   *   The scalars, separated by newlines.
   */
  private function flatten(array $values): string {
    $text = '';
    foreach ($values as $value) {
      $text .= is_array($value) ? $this->flatten($value) : (string) $value . "\n";
    }

    return $text;
  }

}
