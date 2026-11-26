<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use NeuronAI\Evaluation\BaseEvaluator;

/**
 * Base class for the evaluators that run against an editorial session.
 *
 * An evaluator has to leave the site as it found it. So each one creates
 * a throwaway session, reloads it between turns, and deletes the session
 * and its owner at the end.
 */
abstract class EditorialSessionEvaluatorBase extends BaseEvaluator {

  /**
   * Creates a throwaway session owned by a throwaway user.
   *
   * @param array $selections
   *   The selections the session starts with. Both are optional: 'tone'
   *   names a tone term, 'template' is the id of a drafting template.
   *
   * @return array
   *   The new session, then the user that owns it.
   */
  protected function createSession(array $selections = []): array {
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
    if (isset($selections['tone'])) {
      $values['tone'] = $this->tone($selections['tone'])->id();
    }
    if (isset($selections['template'])) {
      $values['template'] = $selections['template'];
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
