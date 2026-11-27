<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the assistant answers what happened in the session it serves.
 *
 * The editorial agent answers such a question through
 * context_get_session_activity, which reads the activity record. Each
 * checkpoint asks one question right after the change it is about, so a
 * failure names the change the record lost.
 */
final class SessionActivityAnswerEvaluator extends EditorialSessionEvaluatorBase {

  use EvaluationDocumentsTrait;

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'input' => [
          'tone' => 'Formal',
          'changed_tone' => 'Technical',
          'document' => 'Fishing quotas',
        ],
        // One fact per checkpoint. A judge scores a reply that answers half
        // of a two-part question as half wrong, so the count of the tone
        // changes and the tone before them are asked apart. Each entry is a
        // criterion the reply has to meet, not a reply to match: the
        // assistant volunteers what a document covers, and a judge asked
        // for equivalence marks that extra detail as a difference.
        'expected_output' => [
          'created' => 'The reply says the session was created with the Formal tone.',
          'tone_change_count' => 'The reply says the tone changed once.',
          'tone_before_change' => 'The reply says the tone was Formal before the change.',
          'document_attached' => 'The reply says a document named "Fishing quotas" is attached.',
          'document_removed' => 'The reply says the document named "Fishing quotas" was removed.',
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
   *   The turn of each checkpoint, keyed as 'expected_output' is.
   */
  public function run(array $datasetItem): mixed {
    $input = $datasetItem['input'];
    [$session, $owner] = $this->createSession(tone: $input['tone']);
    $sessionId = (int) $session->id();

    try {
      $answers = [
        'created' => $this->ask($sessionId, 'What tone was this session created with?'),
      ];

      $this->setTone($sessionId, $input['changed_tone']);
      $answers['tone_change_count'] = $this->ask($sessionId, 'How many times has the tone changed in this session?');
      $answers['tone_before_change'] = $this->ask($sessionId, 'What was the tone of this session before the last change?');

      $documentId = $this->attachFixtureDocument(
        $this->session($sessionId),
        $input['document'],
        $this->fixtureFiles()[$input['document']],
      );
      $answers['document_attached'] = $this->ask($sessionId, 'Which documents are attached to this session?');

      \Drupal::service(ContextDocumentRepository::class)->remove($this->session($sessionId), $documentId);
      $answers['document_removed'] = $this->ask($sessionId, 'What happened to the documents of this session?');
    }
    finally {
      $this->deleteFixtureDocuments();
      $this->deleteSession($sessionId, $owner);
    }

    return $answers;
  }

  /**
   * Scores each answer against what the record held when it was asked.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    $this->assert(
      new ToolWasCalled('context_get_session_activity'),
      $output['tone_before_change'],
      'tool_called',
    );

    foreach ($datasetItem['expected_output'] as $checkpoint => $expected) {
      $this->assert(
        new AgentJudge($this->judge(), $expected),
        $output[$checkpoint]->finalAnswer(),
        $checkpoint,
      );
    }
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

}
