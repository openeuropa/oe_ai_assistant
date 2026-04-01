<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Processor;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\PluginManager\AiShortTermMemoryPluginManager;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Message\AssistantMessage;

/**
 * Wraps Drupal's AiShortTermMemory plugins as a Symfony AI InputProcessor.
 *
 * Converts between Symfony AI's MessageBag and Drupal's ChatMessage[]
 * format, runs the configured memory plugin (e.g. LastN), and applies
 * the trimmed result back to the Input.
 *
 * Tool call metadata is preserved by trimming from the original
 * MessageBag rather than reconstructing from ChatMessage objects
 * (which lose tool call data). This mirrors the approach used by
 * the previous ChatPluginBase::rebuildHistoryFromProcessed().
 */
class ShortTermMemoryInputProcessor implements InputProcessorInterface {

  /**
   * Constructs a ShortTermMemoryInputProcessor.
   *
   * @param \Drupal\ai\PluginManager\AiShortTermMemoryPluginManager $manager
   *   The short-term memory plugin manager.
   * @param string $pluginId
   *   The memory plugin ID (e.g. 'last_n').
   * @param array $pluginConfig
   *   Configuration for the memory plugin instance.
   * @param string $threadId
   *   The conversation thread ID.
   * @param string $assistantId
   *   The assistant plugin ID (used by the memory plugin API).
   */
  public function __construct(
    private readonly AiShortTermMemoryPluginManager $manager,
    private readonly string $pluginId,
    private readonly array $pluginConfig,
    private readonly string $threadId,
    private readonly string $assistantId,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Applies the Drupal short-term memory plugin to the message bag.
   * Trims conversation history while preserving tool call metadata.
   */
  public function processInput(Input $input): void {
    $bag = $input->getMessageBag();
    $messages = $bag->getMessages();
    $systemMessage = $bag->getSystemMessage();

    // Filter out system message for counting -- memory plugin
    // only processes conversation messages.
    $conversationMessages = array_values(array_filter(
      $messages,
      fn(MessageInterface $m) => !$m instanceof SystemMessage,
    ));
    $originalCount = count($conversationMessages);

    if ($originalCount === 0) {
      return;
    }

    // Convert to ChatMessage[] for the Drupal plugin API.
    $chatMessages = $this->toChatMessages($conversationMessages);
    $systemPrompt = $systemMessage?->getContent() ?? '';

    // Create the memory plugin and run processing.
    /** @var \Drupal\ai\Plugin\AiShortTermMemory\AiShortTermMemoryInterface $plugin */
    $plugin = $this->manager->createInstance(
      $this->pluginId, $this->pluginConfig,
    );
    $plugin->process(
      $this->threadId,
      $this->assistantId,
      $chatMessages,
      $systemPrompt,
      [],
      $chatMessages,
      $systemPrompt,
      [],
    );

    // Read back the processed count.
    $processedCount = count($plugin->getChatHistory());

    // Trim from the front of the original messages to preserve
    // tool call metadata that ChatMessage does not carry.
    if ($processedCount < $originalCount) {
      $offset = $originalCount - $processedCount;
      $trimmed = array_slice($conversationMessages, $offset);
      $newBag = new MessageBag(...$trimmed);
      if ($systemMessage !== NULL) {
        $newBag = $newBag->withSystemMessage($systemMessage);
      }
      $input->setMessageBag($newBag);
    }

    // Update system prompt if the memory plugin modified it.
    $modifiedPrompt = $plugin->getSystemPrompt();
    if ($modifiedPrompt !== $systemPrompt && $modifiedPrompt !== '') {
      $currentBag = $input->getMessageBag();
      $input->setMessageBag(
        $currentBag->withSystemMessage(
          Message::forSystem($modifiedPrompt),
        ),
      );
    }
  }

  /**
   * Converts Symfony AI messages to Drupal ChatMessage objects.
   *
   * @param \Symfony\AI\Platform\Message\MessageInterface[] $messages
   *   The Symfony AI messages to convert.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The converted ChatMessage objects.
   */
  private function toChatMessages(array $messages): array {
    $chatMessages = [];
    foreach ($messages as $message) {
      $role = match (TRUE) {
        $message instanceof UserMessage => 'user',
        $message instanceof AssistantMessage => 'assistant',
        default => 'tool',
      };
      $content = match (TRUE) {
        $message instanceof UserMessage => $message->asText() ?? '',
        $message instanceof AssistantMessage => $message->getContent() ?? '',
        default => method_exists($message, 'getContent')
          ? ($message->getContent() ?? '')
          : '',
      };
      $chatMessages[] = new ChatMessage($role, $content);
    }
    return $chatMessages;
  }

}
