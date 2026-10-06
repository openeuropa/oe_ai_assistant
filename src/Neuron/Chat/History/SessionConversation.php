<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The stored conversations of one editorial session.
 *
 * Neuron's own store answers with messages, which is what a model run needs.
 * The transcript the editor reads and the drafts the session holds need the
 * rows: when each one was written, who wrote it, and what a tool answered.
 * Every read here is by the session reference the store stamps on each row, so
 * nothing in here has to know how a thread id is spelled.
 */
final class SessionConversation {

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The rows of one session, oldest first.
   *
   * The session reference is what relates a row to its session, so a read needs
   * no thread id: the store stamps the reference on every row it writes, in the
   * editor's conversation and in a sub-agent run alike.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   * @param string|null $agentId
   *   The agent to read, or NULL for every agent of the session.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The rows.
   */
  public function rows(AiEditorialSessionInterface $session, ?string $agentId = NULL): array {
    $storage = $this->entityTypeManager->getStorage('neuron_message');
    $storage->resetCache();
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('oe_ai_session', (int) $session->id())
      ->sort('id');
    if ($agentId !== NULL) {
      $query->condition('agent_id', $agentId);
    }
    $ids = $query->execute();

    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  /**
   * Every conversation of one session, grouped by thread.
   *
   * A session holds the editor's conversation and one thread per sub-agent
   * run. Threads are ordered by the first row written in each, so the editor's
   * own comes first and a drafter run follows the turn that started it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return array
   *   The rows, keyed by thread id, each thread oldest row first.
   */
  public function threads(AiEditorialSessionInterface $session): array {
    $threads = [];
    foreach ($this->rows($session) as $row) {
      $threads[(string) $row->get('thread_id')->value][] = $row;
    }

    return $threads;
  }

  /**
   * What the tools of one session answered, in the order they ran.
   *
   * Every agent of the session counts: a drafter answers no tool call, so the
   * calls are the editor's conversation whichever agent is asked.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return array
   *   One entry per settled call, shaped {name, result}, the result decoded.
   */
  public function toolResults(AiEditorialSessionInterface $session): array {
    $results = [];
    foreach ($this->rows($session) as $row) {
      $meta = self::decode($row, 'meta');
      if (($meta['type'] ?? '') !== 'tool_call_result') {
        continue;
      }
      foreach ($meta['tools'] ?? [] as $tool) {
        $results[] = [
          'name' => (string) ($tool['name'] ?? ''),
          'result' => self::decodeResult($tool['result'] ?? NULL),
        ];
      }
    }

    return $results;
  }

  /**
   * Removes every conversation of one session, drafter runs included.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   */
  public function deleteFor(AiEditorialSessionInterface $session): void {
    $storage = $this->entityTypeManager->getStorage('neuron_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('oe_ai_session', (int) $session->id())
      ->execute();
    if ($ids !== []) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Decodes one of the JSON columns of a stored message.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $row
   *   The stored message.
   * @param string $field
   *   The column: content, which is a list of content blocks, or meta.
   *
   * @return array
   *   The decoded value, empty when the column holds nothing.
   */
  public static function decode(ContentEntityInterface $row, string $field): array {
    $value = $row->get($field)->value;

    return $value === NULL ? [] : (array) json_decode((string) $value, TRUE);
  }

  /**
   * Decodes what a tool answered, which the tools of this module write as JSON.
   *
   * @param mixed $result
   *   The result as the call serialized it.
   *
   * @return array
   *   The decoded result, empty when it is not a JSON object.
   */
  public static function decodeResult(mixed $result): array {
    if (is_array($result)) {
      return $result;
    }

    return is_string($result) ? (array) json_decode($result, TRUE) : [];
  }

}
