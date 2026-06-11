<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\Event\AiProviderResponseBaseEvent;
use Drupal\ai\Guardrail\Result\GuardrailResultInterface;
use Drupal\ai\OperationType\InputInterface;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Maps AI response events to AI interaction log entity values.
 */
class AiInteractionLogMapper {

  /**
   * Constructs a new mapper.
   */
  public function __construct(
    protected readonly RequestStack $requestStack,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly TimeInterface $time,
  ) {
  }

  /**
   * Maps a post-generate AI response event to entity field values.
   *
   * @return array
   *   Entity field values keyed by field name.
   */
  public function mapResponseEvent(AiProviderResponseBaseEvent $event): array {
    $input = $event->getInput();
    $output = $event->getOutput();
    $configuration = $this->normalizeValue($event->getConfiguration());
    $tags = $this->normalizeValue($event->getTags());
    $guardrails = $this->extractGuardrails($input);
    $metadata = [
      'event_metadata' => $this->normalizeValue($event->getAllMetadata()),
      'debug_data' => $this->normalizeValue($event->getDebugData()),
    ];
    $token_usage = $this->extractTokenUsage($output);
    $request = $this->requestStack->getCurrentRequest();
    $event_timestamp = $this->time->getRequestTime();

    $raw_payload = [
      'event_name' => $event::EVENT_NAME,
      'channel' => 'ai_observability',
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'operation_type' => $event->getOperationType(),
      'provider_request_id' => $event->getRequestThreadId(),
      'provider_parent_request_id' => $event->getRequestParentId(),
      'token_usage' => $token_usage,
      'request_uri' => $request?->getUri(),
      'referer' => $request?->headers->get('referer'),
      'base_url' => $request?->getSchemeAndHttpHost(),
      'user_id' => (int) $this->currentUser->id(),
      'ip' => $request?->getClientIp(),
      'severity' => 'info',
      'timestamp' => $event_timestamp,
      'tags' => $tags,
      'guardrails' => $guardrails,
      'configuration' => $configuration,
      'metadata' => $metadata,
      'input' => $this->normalizePayload($input),
      'output' => $this->normalizePayload($output),
    ];

    return [
      'idempotency_key' => Crypt::hashBase64($event->getRequestThreadId()),
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'event_name' => $event::EVENT_NAME,
      'operation_type' => $event->getOperationType(),
      'channel' => 'ai_observability',
      'provider_request_id' => $event->getRequestThreadId(),
      'provider_parent_request_id' => $event->getRequestParentId(),
      'input_tokens' => $token_usage['input'] ?? NULL,
      'output_tokens' => $token_usage['output'] ?? NULL,
      'total_tokens' => $token_usage['total'] ?? NULL,
      'cached_tokens' => $token_usage['cached'] ?? NULL,
      'reasoning_tokens' => $token_usage['reasoning'] ?? NULL,
      'request_uri' => $request?->getUri(),
      'referer' => $request?->headers->get('referer'),
      'base_url' => $request?->getSchemeAndHttpHost(),
      'user_id' => (int) $this->currentUser->id(),
      'ip' => $request?->getClientIp(),
      'severity' => 'info',
      'event_timestamp' => $event_timestamp,
      'tags' => $this->encode($tags),
      'guardrails' => $this->encode($guardrails),
      'configuration' => $this->encode($configuration),
      'metadata' => $this->encode($metadata),
      'input' => $this->encode($raw_payload['input']),
      'output' => $this->encode($raw_payload['output']),
      'raw_payload' => $this->encode($raw_payload),
    ];
  }

  /**
   * Extracts provider token usage from an output object.
   */
  protected function extractTokenUsage(mixed $output): array {
    if (!is_object($output) || !method_exists($output, 'getTokenUsage')) {
      return [];
    }

    $token_usage = $output->getTokenUsage();
    if (is_object($token_usage) && method_exists($token_usage, 'toArray')) {
      return array_filter(
        $token_usage->toArray(),
        static fn ($value): bool => $value !== NULL,
      );
    }

    return [];
  }

  /**
   * Extracts all guardrail results attached to an input object.
   */
  protected function extractGuardrails(mixed $input): array {
    if (!$input instanceof InputInterface) {
      return [];
    }

    $guardrails = [];
    foreach ($input->getAllGuardrailResults() as $mode => $results) {
      foreach ($results as $result) {
        if (!$result instanceof GuardrailResultInterface) {
          continue;
        }
        $guardrails[] = [
          'mode' => (string) $mode,
          'guardrail' => $result->getGuardrailLabel(),
          'type' => get_debug_type($result),
          'context' => $this->normalizeValue($result->getContext()),
          'message' => $result->getMessage(),
          'stop' => $result->stop(),
        ];
      }
    }

    return $guardrails;
  }

  /**
   * Normalizes input or output payloads without truncating content.
   */
  protected function normalizePayload(mixed $payload): mixed {
    if ($payload instanceof InputInterface) {
      return [
        'string' => $payload->toString(),
        'data' => $this->normalizeValue($payload->toArray()),
      ];
    }

    if ($payload instanceof OutputInterface) {
      return [
        'data' => $this->normalizeValue($payload->toArray()),
      ];
    }

    return $this->normalizeValue($payload);
  }

  /**
   * Normalizes arbitrary values into JSON-serializable data.
   */
  protected function normalizeValue(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) {
      return get_debug_type($value);
    }

    if ($value === NULL || is_scalar($value)) {
      return $value;
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalizeValue($item, $depth + 1);
      }
      return $normalized;
    }

    if ($value instanceof \JsonSerializable) {
      return $this->normalizeValue($value->jsonSerialize(), $depth + 1);
    }

    if (is_object($value) && method_exists($value, 'toArray')) {
      return $this->normalizeValue($value->toArray(), $depth + 1);
    }

    if ($value instanceof \Stringable) {
      return (string) $value;
    }

    return ['class' => get_debug_type($value)];
  }

  /**
   * Encodes a normalized value as JSON.
   */
  protected function encode(mixed $value): string {
    return Json::encode($value);
  }

}
