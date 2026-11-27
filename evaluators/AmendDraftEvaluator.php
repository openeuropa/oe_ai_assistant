<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Evaluators\Assertions\BooleanFact;
use Drupal\oe_ai_assistant\Utility\DraftVersion;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;

/**
 * Checks amend_draft against a plain session: no document fixtures involved.
 */
final class AmendDraftEvaluator extends EditorialSessionEvaluatorBase {

  private const string BRIEF = 'Draft a news item about the launch of the "Solaris Express"'
    . ' high-speed rail corridor between Lindau and Varonne, opened today by the regional'
    . ' transport ministers. Use those names and write the draft now.';

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'input' => [
          'case' => 'asks_to_revise',
          'amendment' => 'The headline is weak. Make it punchier.',
        ],
        // evaluate() checks fixed facts here, nothing from the dataset.
        'expected_output' => [],
      ],
      [
        'input' => [
          'case' => 'one_field',
          'amendment' => 'Just replace the quote with a better one that fits the topic.'
          . ' Leave everything else exactly as it is.',
        ],
        // evaluate() compares run()'s own before/after text, not this.
        'expected_output' => [],
      ],
      [
        'input' => [
          'case' => 'whole_draft',
          'amendment' => 'This whole draft reads flat. Make it punchier and more engaging throughout.',
        ],
        // evaluate() compares run()'s own before/after text, not this.
        'expected_output' => [],
      ],
      [
        'input' => [
          'case' => 'unknown_version',
          'amendment' => 'Please amend draft 5 and make the title punchier.',
        ],
        'expected_output' => [
          'reports_the_draft_does_not_exist' => 'The reply makes clear that no draft numbered 5'
          . ' exists in this session, whether by saying so directly or by naming which draft'
          . ' numbers do exist (just Draft 1) and asking if the editor meant one of those. It'
          . ' does not claim any new draft is now ready.',
        ],
      ],
    ]);
  }

  /**
   * Drafts once, then runs the amendment turn the case asks for.
   *
   * @param array $datasetItem
   *   The item to run, naming its case and its amendment request.
   *
   * @return array
   *   The amendment turn, and the draft's sections before and after it.
   */
  public function run(array $datasetItem): mixed {
    $input = $datasetItem['input'];
    [$session, $owner] = $this->createSession(template: 'news_with_paragraphs');
    $sessionId = (int) $session->id();

    try {
      $draftingTurn = $this->ask($sessionId, self::BRIEF);
      if ($this->session($sessionId)->listDrafts() === []) {
        $draftingTurn = $this->ask($sessionId, 'Yes, use those names and write the draft now.');
      }

      if ($this->session($sessionId)->listDrafts() === []) {
        throw new \RuntimeException(sprintf(
          'No draft was stored even after confirming. Tools called: %s. Final answer: %s',
          implode(', ', array_map(
            static fn (ToolCall $call): string => $call->getName(),
            $draftingTurn->toolCalls(),
          )) ?: '(none)',
          $draftingTurn->finalAnswer(),
        ));
      }

      $needsSections = in_array($input['case'], ['one_field', 'whole_draft'], TRUE);
      $before = $needsSections ? $this->draftSections($sessionId) : NULL;

      $turn = $this->ask($sessionId, $input['amendment']);

      $after = $needsSections ? $this->draftSections($sessionId) : NULL;

      return ['turn' => $turn, 'before' => $before, 'after' => $after];
    }
    finally {
      $this->deleteSession($sessionId, $owner);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    match ($datasetItem['input']['case']) {
      'asks_to_revise' => $this->evaluateRevisesExisting($output['turn']),
      'one_field' => $this->evaluateOneFieldAmendment($output),
      'whole_draft' => $this->evaluateWholeDraftAmendment($output),
      'unknown_version' => $this->evaluateUnknownVersion($output['turn'], $datasetItem),
    };
  }

  /**
   * Checks the turn called amend_draft on the first draft, not a fresh one.
   */
  private function evaluateRevisesExisting(Trajectory $turn): void {
    $this->assert(
      new ToolWasCalled('amend_draft', static fn (array $args): bool =>
        DraftVersion::normalize((string) ($args['version'] ?? '')) === '1.0'),
      $turn,
      'calls_amend_draft_with_the_source_version',
    );
    $this->assert(
      new ToolWasNotCalled('drafting_draft_content'),
      $turn,
      'does_not_start_a_fresh_draft',
    );
    $this->assert(
      new StringContains('Draft 1.1'),
      $turn->finalAnswer(),
      'reports_the_new_version',
    );
  }

  /**
   * Checks the body paragraphs changed and the headline/teaser did not.
   */
  private function evaluateOneFieldAmendment(array $output): void {
    ['turn' => $turn, 'before' => $before, 'after' => $after] = $output;

    $this->assert(
      new ToolWasCalled('amend_draft', static fn (array $args): bool => !empty($args['groups'] ?? [])),
      $turn,
      'scopes_to_the_body_paragraphs',
    );
    $this->assert(
      new BooleanFact('The headline or teaser changed even though the editor only asked about the quote.'),
      $before['headlineAndTeaser'] === $after['headlineAndTeaser'],
      'leaves_the_headline_and_teaser_as_they_were',
    );
    $this->assert(
      new BooleanFact('The body paragraphs did not change, even though the editor asked to replace the quote.'),
      $before['bodyParagraphs'] !== $after['bodyParagraphs'],
      'changes_the_body_paragraphs',
    );
  }

  /**
   * Checks both the headline/teaser and the body paragraphs changed.
   */
  private function evaluateWholeDraftAmendment(array $output): void {
    ['turn' => $turn, 'before' => $before, 'after' => $after] = $output;

    $this->assert(
      new ToolWasCalled('amend_draft', static fn (array $args): bool => empty($args['groups'] ?? [])),
      $turn,
      'touches_every_section',
    );
    $this->assert(
      new BooleanFact('The headline and teaser stayed exactly as they were, even though the request was general.'),
      $before['headlineAndTeaser'] !== $after['headlineAndTeaser'],
      'amendment_reaches_the_headline_and_teaser',
    );
    $this->assert(
      new BooleanFact('The body paragraphs stayed exactly as they were, even though the request was general.'),
      $before['bodyParagraphs'] !== $after['bodyParagraphs'],
      'amendment_reaches_the_body_paragraphs',
    );
  }

  /**
   * Checks the reply reports the missing draft instead of claiming success.
   */
  private function evaluateUnknownVersion(Trajectory $turn, array $datasetItem): void {
    $this->assert(
      new AgentJudge($this->judge(), $datasetItem['expected_output']['reports_the_draft_does_not_exist']),
      $turn->finalAnswer(),
      'reports_the_draft_does_not_exist',
    );
  }

  /**
   * The two sections of the draft the session stored last, as plain text.
   *
   * @param int $sessionId
   *   The session that holds the draft.
   *
   * @return array
   *   Keyed "headlineAndTeaser" and "bodyParagraphs".
   */
  private function draftSections(int $sessionId): array {
    $session = $this->session($sessionId);
    assert($session instanceof ContentCreationSessionInterface);
    $drafts = $session->listDrafts();
    if ($drafts === []) {
      throw new \RuntimeException('The run stored no draft to read.');
    }

    $latest = end($drafts);
    $draft = $session->getDraftContent((string) $latest['version']);
    if ($draft === NULL) {
      throw new \RuntimeException(sprintf('Draft %s holds no content.', $latest['version']));
    }

    $fields = $draft['fields'];
    $headlineAndTeaser = ($fields['title'][0]['value'] ?? '') . "\n" . ($fields['field_teaser'][0]['value'] ?? '');
    $bodyParagraphs = json_encode($fields['field_content_paragraphs'] ?? []);

    return ['headlineAndTeaser' => $headlineAndTeaser, 'bodyParagraphs' => $bodyParagraphs];
  }

}
