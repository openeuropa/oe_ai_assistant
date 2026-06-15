<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\AudienceToneManagerInterface;
use Drupal\oe_ai_assistant\Store\DraftingSelectionStoreInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides audience and tone API endpoints.
 */
class AudienceToneController extends ControllerBase {

  public function __construct(
    private readonly AudienceToneManagerInterface $audienceToneManager,
    private readonly DraftingSelectionStoreInterface $selectionStore,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly AccountProxyInterface $currentUserAccount,
  ) {}

  /**
   * Lists selectable options for the requested type.
   *
   * @param string $option_type
   *   The option type: "audience" or "tone".
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The requested options.
   */
  public function list(string $option_type): JsonResponse {
    try {
      $options = $this->audienceToneManager->getOptions($option_type);
    }
    catch (\InvalidArgumentException $e) {
      return $this->error('invalid_option_type', $e->getMessage(), 400);
    }

    return new JsonResponse([
      'type' => $option_type,
      'options' => $options,
    ]);
  }

  /**
   * Temporarily saves the selected option for the requested type.
   *
   * @param string $option_type
   *   The option type: "audience" or "tone".
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The saved selection.
   */
  public function saveSelection(string $option_type, Request $request): JsonResponse {
    try {
      $body = $this->decodeJsonBody($request);
      $this->assertAllowedRequestKeys($body, [
        'selectedId',
        'entityTypeId',
        'bundle',
        'threadId',
        'sessionId',
      ]);
      $context = $this->buildContext($body);
      $this->assertAccess($context);
      $selectedId = $this->requireString($body, 'selectedId');
      $selection = $this->audienceToneManager
        ->validateSelection($option_type, $selectedId);
    }
    catch (\InvalidArgumentException $e) {
      return $this->error('invalid_selection', $e->getMessage(), 400);
    }
    catch (\RuntimeException $e) {
      return $this->error('invalid_request', $e->getMessage(), 400);
    }
    catch (\DomainException $e) {
      return $this->error('forbidden', $e->getMessage(), 403);
    }

    $this->selectionStore->save($context, $option_type, $selectedId);

    return new JsonResponse([
      'selection' => [
        'type' => $option_type,
        'value' => $selection,
      ],
    ]);
  }

  /**
   * Decodes a JSON object request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, mixed>
   *   The decoded request body.
   */
  private function decodeJsonBody(Request $request): array {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || array_is_list($body)) {
      throw new \RuntimeException('Request body must be a JSON object.');
    }

    return $body;
  }

  /**
   * Rejects unsupported request properties.
   *
   * @param array<string, mixed> $body
   *   The decoded request body.
   * @param string[] $allowedKeys
   *   The allowed keys.
   */
  private function assertAllowedRequestKeys(array $body, array $allowedKeys): void {
    $unknown = array_values(array_diff(array_keys($body), $allowedKeys));
    if ($unknown !== []) {
      throw new \RuntimeException(sprintf(
        'Unsupported request properties: %s.',
        implode(', ', $unknown),
      ));
    }
  }

  /**
   * Builds the selection persistence context.
   *
   * @param array<string, mixed> $body
   *   The decoded request body.
   *
   * @return array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string}
   *   The persistence context.
   */
  private function buildContext(array $body): array {
    $context = [
      'entityTypeId' => $this->requireString($body, 'entityTypeId'),
      'bundle' => $this->requireString($body, 'bundle'),
    ];

    foreach (['threadId', 'sessionId'] as $optionalKey) {
      if (array_key_exists($optionalKey, $body)) {
        $context[$optionalKey] = $this->requireString($body, $optionalKey);
      }
    }

    return $context;
  }

  /**
   * Requires a non-empty string field.
   *
   * @param array<string, mixed> $body
   *   The decoded request body.
   * @param string $key
   *   The required key.
   *
   * @return string
   *   The string value.
   */
  private function requireString(array $body, string $key): string {
    $value = $body[$key] ?? NULL;
    if (!is_string($value) || trim($value) === '') {
      throw new \RuntimeException(sprintf(
        '%s is required and must be a non-empty string.',
        $key,
      ));
    }

    return $value;
  }

  /**
   * Checks access for temporary selection changes.
   *
   * @param array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string} $context
   *   The selection context.
   */
  private function assertAccess(array $context): void {
    if ($context['entityTypeId'] !== 'node') {
      throw new \RuntimeException('Only node drafting contexts are supported.');
    }

    if (!$this->entityTypeManagerService->getStorage('node_type')->load($context['bundle'])) {
      throw new \RuntimeException(sprintf(
        'Content type "%s" does not exist.',
        $context['bundle'],
      ));
    }

    $sessionAccessHandler = $this->entityTypeManagerService
      ->getAccessControlHandler('ai_editorial_session');
    if (!$sessionAccessHandler->createAccess('content_creation', $this->currentUserAccount, [
      'content_type' => $context['bundle'],
    ])) {
      throw new \DomainException(sprintf(
        'You do not have permission to create %s content.',
        $context['bundle'],
      ));
    }

    if (empty($context['sessionId'])) {
      return;
    }

    $session = $this->entityTypeManagerService
      ->getStorage('ai_editorial_session')
      ->load($context['sessionId']);
    if (!$session instanceof AiEditorialSessionInterface) {
      throw new \RuntimeException(sprintf(
        'AI editorial session "%s" does not exist.',
        $context['sessionId'],
      ));
    }
    if ($session->getContentType() !== $context['bundle']) {
      throw new \RuntimeException('The requested bundle does not match the AI editorial session.');
    }
    if (!$session->access('update', $this->currentUserAccount)) {
      throw new \DomainException('You do not have permission to update this AI editorial session.');
    }
  }

  /**
   * Returns a flat JSON error response.
   */
  private function error(string $code, string $message, int $status): JsonResponse {
    return new JsonResponse([
      'code' => $code,
      'message' => $message,
    ], $status);
  }

}
