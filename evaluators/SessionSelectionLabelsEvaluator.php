<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the assistant names the selections of a new session.
 *
 * A tone and a template are labels the editor picked, and an answer either
 * carries them or it does not. So this evaluator asserts on the strings and
 * calls no judge: it costs one turn of one agent, and it fails on the
 * record losing a value rather than on a model wording an answer its own
 * way.
 */
final class SessionSelectionLabelsEvaluator extends EditorialSessionEvaluatorBase {

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        // The site installs these two, and the labels are what the editor
        // reads, so they are what the answer has to carry.
        'input' => [
          'tone' => 'Formal',
          'template' => 'news_with_paragraphs',
        ],
        'expected_output' => [
          'tone' => 'Formal',
          'template' => 'News article with paragraphs',
        ],
      ],
    ]);
  }

  /**
   * Asks a new session which tone and template it holds.
   *
   * @param array $datasetItem
   *   The item to run, carrying the selections under 'input'.
   *
   * @return string
   *   The answer of the one turn this evaluator takes.
   */
  public function run(array $datasetItem): mixed {
    [$session, $owner] = $this->createSession($datasetItem['input']);
    $sessionId = (int) $session->id();

    try {
      $agent = \Drupal::service(NeuronAgentManagerInterface::class)
        ->createAgent('editorial', ['session' => $this->session($sessionId)]);
      $agent->chat(new UserMessage('Which tone and which template is this session set up with?'));

      return Trajectory::fromChatHistory($agent->getChatHistory())->finalAnswer();
    }
    finally {
      $this->deleteSession($sessionId, $owner);
    }
  }

  /**
   * Checks that the answer names both selections.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    assert(is_string($output));

    foreach ($datasetItem['expected_output'] as $selection => $label) {
      $this->assert(new StringContains($label), $output, $selection);
    }
  }

}
