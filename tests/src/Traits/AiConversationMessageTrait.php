<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Trait that contains methods to handle AI conversation message entities.
 */
trait AiConversationMessageTrait {

  /**
   * Creates and saves a conversation message hosted by the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $role
   *   The message role.
   * @param string $content
   *   The message content.
   * @param int|null $parent
   *   The message parent ID.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   An AI conversation message entity.
   */
  protected function createMessage(EntityInterface $host, string $role, string $content, ?int $parent = NULL): AiConversationMessageInterface {
    $message = AiConversationMessage::create([
      'host_entity_type' => $host->getEntityTypeId(),
      'host_entity_id' => (int) $host->id(),
      'parent' => $parent,
      'role' => $role,
      'content' => $content,
    ]);
    $message->save();
    return $message;
  }

  /**
   * Creates a top-level assistant turn that called draft_content.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param array<string, int> $usage
   *   Optional token usage to store on the turn.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved turn.
   */
  protected function createDraftTurn(EntityInterface $host, array $usage = []): AiConversationMessageInterface {
    $message = $this->createMessage($host, AiConversationMessageInterface::ROLE_ASSISTANT, 'Draft ready.');
    $message->set('provider', 'mock');
    $message->set('model', 'mock-model');
    $message->setToolCalls([
      ['type' => 'function', 'function' => ['name' => 'draft_content', 'arguments' => '{}']],
    ]);
    if ($usage !== []) {
      $message->setTokenUsage($usage);
    }
    $message->save();
    return $message;
  }

}
