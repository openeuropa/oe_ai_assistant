<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Maps Neuron messages to drupal/ai chat messages.
 */
final class MessageMapper implements MessageMapperInterface {

  /**
   * {@inheritdoc}
   *
   * A tool result message becomes one tool role message per tool, each
   * carrying the call id the model used. A message with several text blocks
   * becomes one chat message per block, so merged turns keep their texts.
   */
  public function map(array $messages): array {
    $mapped = [];
    foreach ($messages as $message) {
      if ($message instanceof ToolResultMessage) {
        foreach ($message->getTools() as $tool) {
          $result = new ChatMessage('tool', $tool->getResult());
          $result->setToolsId((string) $tool->getCallId());
          $mapped[] = $result;
        }
        continue;
      }
      if ($message instanceof ToolCallMessage) {
        $call = new ChatMessage('assistant', $message->getContent() ?? '');
        $call->setTools(array_map($this->toolCall(...), $message->getTools()));
        $mapped[] = $call;
        continue;
      }
      foreach ($this->texts($message) as $text) {
        $mapped[] = new ChatMessage($message->getRole(), $text);
      }
    }
    return $mapped;
  }

  /**
   * Returns the text of each block, or one empty text for a bare message.
   */
  private function texts(Message $message): array {
    $texts = [];
    foreach ($message->getTextBlocks() as $block) {
      $texts[] = $block->content;
    }
    return $texts === [] ? [''] : $texts;
  }

  /**
   * Renders a requested tool call in the shape drupal/ai providers expect.
   */
  private function toolCall(ToolInterface $tool): ToolsFunctionOutput {
    $output = new ToolsFunctionOutput(NULL, (string) $tool->getCallId(), $tool->getInputs());
    $output->setName($tool->getName());
    return $output;
  }

}
