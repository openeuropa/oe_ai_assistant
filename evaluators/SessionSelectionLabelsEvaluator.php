<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\user\Entity\User;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
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
final class SessionSelectionLabelsEvaluator extends BaseEvaluator {

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
      // Nested so the throwaway user goes even when deleting the session
      // throws.
      try {
        $this->session($sessionId)->delete();
      }
      finally {
        $owner->delete();
      }
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

  /**
   * Loads the session again, the way every request does.
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
   * Creates a throwaway session, with both selections made.
   *
   * @param array $input
   *   The name of the tone term and the id of the template to create with.
   *
   * @return array
   *   The new session, then the user that owns it.
   */
  private function createSession(array $input): array {
    $user = User::create([
      'name' => 'eval-' . uniqid(),
      'mail' => 'eval-' . uniqid() . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'oe_ai_tone', 'name' => $input['tone']]);
    $tone = reset($terms);
    if ($tone === FALSE) {
      throw new \RuntimeException(sprintf('The site publishes no "%s" tone.', $input['tone']));
    }

    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->create([
      'type' => 'content_creation',
      'uid' => $user->id(),
      'content_type' => 'oe_news',
      'tone' => $tone->id(),
      'template' => $input['template'],
    ]);
    $session->save();

    return [$session, $user];
  }

}
