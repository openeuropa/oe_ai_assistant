<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\Response\AiStreamedResponse;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test plugin for agent/sub-agent orchestration spike.
 *
 * Streams LLM responses as SSE events using the Vercel AI SDK
 * UI Message Stream v1 protocol.
 */
#[AiEditorialAssistant(
  id: 'agent_test',
  label: 'Agent Test',
  description: 'Test plugin for agent/sub-agent orchestration.',
)]
class AgentTestPlugin extends AiAssistantPluginBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->aiProviderManager = $container->get('ai.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return [
      'chat' => $this->chat(...),
    ];
  }

  /**
   * Handles a chat request by calling the LLM and streaming the response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a JSON body containing 'message'.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The SSE streaming response.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);
    $message = $body['message'] ?? '';

    // Build a ChatInput with the user's message and streaming enabled.
    $chatInput = new ChatInput([
      new ChatMessage('user', $message),
    ]);
    $chatInput->setStreamedOutput(TRUE);

    // Resolve the provider. State overrides allow tests to specify
    // which provider to use without conflicting with settings.php
    // config overrides. Falls back to the site default.
    $state = \Drupal::state();
    $providerId = $state->get('agent_test.provider_id');
    $modelId = $state->get('agent_test.model_id');
    if ($providerId && $modelId) {
      $provider = $this->aiProviderManager->createInstance($providerId);
    }
    else {
      $defaults = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $provider = $this->aiProviderManager->createInstance($defaults['provider_id']);
      $modelId = $defaults['model_id'];
    }

    // Call the LLM.
    $chatOutput = $provider->chat($chatInput, $modelId, ['agent_test']);

    // Stream the response as SSE using AiStreamedResponse from drupal/ai.
    $response = new AiStreamedResponse(NULL, 200, [
      'Content-Type' => 'text/event-stream',
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);

    $response->setCallback(function () use ($chatOutput): void {
      set_time_limit(0);

      $messageId = bin2hex(random_bytes(16));

      // Emit start event.
      $this->emitSseEvent('start', ['messageId' => $messageId]);

      // Emit start-step event.
      $this->emitSseEvent('start-step', []);

      // Stream the LLM response.
      $normalized = $chatOutput->getNormalized();

      if ($normalized instanceof StreamedChatMessageIteratorInterface) {
        // Streamed response: emit text-delta events for each chunk.
        foreach ($normalized as $chunk) {
          $text = $chunk->getText();
          if ($text !== '' && $text !== NULL) {
            $this->emitSseEvent('text-delta', ['textDelta' => $text]);
          }
        }
      }
      else {
        // Non-streamed response: emit the full text at once.
        $text = $normalized->getText();
        if ($text !== '' && $text !== NULL) {
          $this->emitSseEvent('text-delta', ['textDelta' => $text]);
        }
      }

      // Emit finish-step event.
      $this->emitSseEvent('finish-step', []);

      // Emit finish event.
      $this->emitSseEvent('finish', [
        'finishReason' => 'stop',
        'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
      ]);

      echo "data: [DONE]\n\n";
      flush();
    });

    return $response;
  }

  /**
   * Emits an SSE event in the Vercel AI SDK UI Message Stream format.
   *
   * @param string $type
   *   The event type (e.g. 'text-delta', 'start', 'finish').
   * @param array $data
   *   The event data payload.
   */
  protected function emitSseEvent(string $type, array $data): void {
    $json = json_encode(
      ['type' => $type, 'data' => $data],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    echo "data: $json\n\n";
    flush();
  }

}
