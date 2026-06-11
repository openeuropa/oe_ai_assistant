<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_test\Plugin\AiProvider;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;

/**
 * Programmable mock AI provider with FIFO response queue.
 *
 * Tests enqueue MockResponse objects before triggering plugin endpoints.
 * Each chat() call dequeues the next response and streams it as word
 * tokens, matching real LLM streaming behavior. Supports both text and
 * tool-call responses.
 */
#[AiProvider(
  id: 'mock_ai',
  label: new TranslatableMarkup('Mock AI Provider'),
)]
class MockAiProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * The Drupal state key for the response queue.
   */
  protected const QUEUE_KEY = 'mock_ai_provider.queue';

  /**
   * The Drupal state key for the call log.
   */
  protected const LOG_KEY = 'mock_ai_provider.call_log';

  /**
   * Enqueues a mock response to the FIFO queue.
   *
   * Uses Drupal's state API so data persists across the test runner
   * process and the web server process handling HTTP requests.
   *
   * @param \Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse $response
   *   The mock response.
   */
  public static function enqueue(MockResponse $response): void {
    $state = \Drupal::state();
    $queue = $state->get(static::QUEUE_KEY, []);
    $queue[] = serialize($response);
    $state->set(static::QUEUE_KEY, $queue);
  }

  /**
   * Resets the queue and call log.
   */
  public static function reset(): void {
    $state = \Drupal::state();
    $state->set(static::QUEUE_KEY, []);
    $state->set(static::LOG_KEY, []);
  }

  /**
   * Returns whether the response queue is empty.
   *
   * @return bool
   *   TRUE if all responses have been consumed.
   */
  public static function isEmpty(): bool {
    $queue = \Drupal::state()->get(static::QUEUE_KEY, []);
    return empty($queue);
  }

  /**
   * Returns the call log entries.
   *
   * Call log entries are serialized arrays with 'system_prompt' and
   * 'messages' keys, since ChatInput objects cannot be reliably
   * serialized across processes.
   *
   * @return array
   *   Array of call log entries.
   */
  public static function getCallLog(): array {
    return \Drupal::state()->get(static::LOG_KEY, []);
  }

  /**
   * Dequeues the next mock response.
   *
   * @return \Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse
   *   The next queued response.
   *
   * @throws \RuntimeException
   *   When the queue is empty.
   */
  protected static function dequeue(): MockResponse {
    $state = \Drupal::state();
    $queue = $state->get(static::QUEUE_KEY, []);
    if (empty($queue)) {
      throw new \RuntimeException('MockAiProvider: no more responses in queue.');
    }
    $serialized = array_shift($queue);
    $state->set(static::QUEUE_KEY, $queue);
    // phpcs:ignore -- MockResponse is a known safe class from this module.
    return unserialize($serialized, ['allowed_classes' => [MockResponse::class]]);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    // Normalize input to ChatInput.
    if (is_string($input)) {
      $input = new ChatInput([new ChatMessage('user', $input)]);
    }
    elseif (is_array($input)) {
      $input = new ChatInput($input);
    }

    // Log the input for test assertions. Store a serializable summary
    // since ChatInput may not survive serialization across processes.
    $state = \Drupal::state();
    $log = $state->get(static::LOG_KEY, []);
    $log[] = [
      'system_prompt' => $input->getSystemPrompt() ?? '',
      'tools' => $input->getChatTools() ? $input->getChatTools()->renderToolsArray() : [],
      'messages' => array_map(fn(ChatMessage $m) => [
        'role' => $m->getRole(),
        'text' => $m->getText(),
      ], $input->getMessages()),
    ];
    $state->set(static::LOG_KEY, $log);

    $response = static::dequeue();

    // Throw error if the response is configured to fail.
    if ($response->hasError()) {
      throw $response->createError();
    }

    // Streaming mode: return a ChatOutput wrapping the iterator.
    if ($input->isStreamedOutput()) {
      $iterator = MockChatMessageIterator::fromMockResponse($response);
      return new ChatOutput($iterator, $input->getMessages(), []);
    }

    // Non-streaming mode: return complete message at once.
    $message = new ChatMessage('assistant', $response->text);
    return new ChatOutput($message, $input->getMessages(), []);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return ['mock-model' => 'Mock Model'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedCapabilities(): array {
    return [
      AiModelCapability::ChatTools->value,
      AiModelCapability::ChatSystemRole->value,
      AiModelCapability::ChatJsonOutput->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // No authentication needed for mock provider.
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('system.site');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

}
