<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Service\ContentTypeSchemaExtractor;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns content type schemas as JSON.
 *
 * Supports two modes via the ?mode= query parameter:
 * - "data" (default): raw field definitions, types, constraints, references
 * - "form": form-aware schema with widget types, interaction modes, tab groups
 */
class ContentSchemaController extends ControllerBase {

  public function __construct(
    private readonly ContentTypeSchemaExtractor $schemaExtractor,
    private readonly FormSchemaExtractor $formSchemaExtractor,
  ) {}

  /**
   * Returns the schema for the given entity type and bundle.
   */
  public function get(string $entity_type_id, string $bundle, Request $request): JsonResponse {
    $mode = $request->query->get('mode', 'form');

    $schema = match ($mode) {
      'data' => $this->schemaExtractor->extract($entity_type_id, $bundle),
      default => $this->formSchemaExtractor->extract($entity_type_id, $bundle),
    };

    return new JsonResponse($schema);
  }

}
