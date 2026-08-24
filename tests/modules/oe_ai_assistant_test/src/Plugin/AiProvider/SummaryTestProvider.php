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
 * Deterministic provider for document summary functional tests.
 */
#[AiProvider(
  id: 'summary_test',
  label: new TranslatableMarkup('Summary test provider'),
)]
class SummaryTestProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $message = new ChatMessage('assistant', 'Extracted context summary.');
    $messages = $input instanceof ChatInput ? $input->getMessages() : [];
    return new ChatOutput($message, $messages, []);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return ['summary-test-model' => 'Summary test model'];
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
    return ['chat_with_image_vision'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedCapabilities(): array {
    return [
      AiModelCapability::ChatSystemRole->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {}

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
