<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Conversation\Trajectory;

/**
 * Base class for the evaluators that run against an editorial session.
 *
 * An evaluator has to leave the site as it found it. So each one creates
 * a throwaway session, reloads it between turns, and deletes the session
 * and its owner at the end.
 */
abstract class EditorialSessionEvaluatorBase extends BaseEvaluator {

  /**
   * The judge, built on first use.
   */
  private ?AgentInterface $judge = NULL;

  /**
   * Asks the editorial agent one question and returns the turn it ran.
   *
   * An agent answers through the session object it was built with, and that
   * object caches the tone, the template and the documents on first read.
   * So every turn builds its own agent on a freshly loaded session, the way
   * a request does, and the stored thread carries the conversation from one
   * turn to the next.
   *
   * @param int $sessionId
   *   The session to ask about.
   * @param string $question
   *   The question to ask.
   *
   * @return \NeuronAI\Evaluation\Conversation\Trajectory
   *   The turn, which holds its answer and the tools it called.
   */
  protected function ask(int $sessionId, string $question): Trajectory {
    $agent = \Drupal::service(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $this->session($sessionId)]);

    // The message store holds every earlier turn of this session, and a
    // trajectory over the whole history reports their tool calls as this
    // turn's. An assertion on a call would then pass on a turn that made
    // none, so we keep only the messages chat() added.
    $earlierTurns = count($agent->getChatHistory()->getMessages());
    $agent->chat(new UserMessage($question));

    return Trajectory::fromMessages(
      array_slice($agent->getChatHistory()->getMessages(), $earlierTurns),
    );
  }

  /**
   * The judge that scores an answer against a criterion.
   *
   * A judge answers with a JudgeScoreOutput, so plain chat is not enough:
   * the provider has to support structured responses. It is built on first
   * use, so an evaluator that asserts on strings alone never builds one.
   *
   * @return \NeuronAI\Agent\AgentInterface
   *   The judge.
   */
  protected function judge(): AgentInterface {
    return $this->judge ??= Agent::make()->setAiProvider(
      \Drupal::service(ProviderFactoryInterface::class)
        ->create('chat_with_structured_response', ['evaluation_judge']),
    );
  }

  /**
   * Creates a throwaway session owned by a throwaway user.
   *
   * @param string|null $tone
   *   The name of the tone term the session starts with, or NULL for none.
   * @param string|null $template
   *   The id of the drafting template it starts with, or NULL for none.
   *
   * @return array
   *   The new session, then the user that owns it.
   */
  protected function createSession(?string $tone = NULL, ?string $template = NULL): array {
    $user = User::create([
      'name' => 'eval-' . uniqid(),
      'mail' => 'eval-' . uniqid() . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    $values = [
      'type' => 'content_creation',
      'uid' => $user->id(),
      'content_type' => 'oe_news',
    ];
    if ($tone !== NULL) {
      $values['tone'] = $this->tone($tone)->id();
    }
    if ($template !== NULL) {
      $values['template'] = $template;
    }

    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->create($values);
    $session->save();

    return [$session, $user];
  }

  /**
   * Deletes the session and the user that owns it.
   *
   * We delete the user in a nested finally. A session that fails to load,
   * or fails to delete, then leaves no throwaway user behind.
   *
   * @param int $sessionId
   *   The session to delete.
   * @param \Drupal\user\UserInterface $owner
   *   The user that owns it.
   */
  protected function deleteSession(int $sessionId, UserInterface $owner): void {
    try {
      $this->session($sessionId)->delete();
    }
    finally {
      $owner->delete();
    }
  }

  /**
   * Loads the session fresh, the way a new request does.
   *
   * ContentCreationSession caches the tone, the template and the documents
   * on first read. If we reused one session object, a turn after a change
   * would still see the state from before it.
   *
   * @param int $id
   *   The session to load.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session.
   */
  protected function session(int $id): AiEditorialSessionInterface {
    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->loadUnchanged($id);
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * Loads the tone term with the given name.
   *
   * @param string $label
   *   The name of the term.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  protected function tone(string $label): TermInterface {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'oe_ai_tone', 'name' => $label]);
    $term = reset($terms);
    if (!$term instanceof TermInterface) {
      throw new \RuntimeException(sprintf('The site publishes no "%s" tone.', $label));
    }

    return $term;
  }

}
