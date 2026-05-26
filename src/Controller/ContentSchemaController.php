<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns the composed JSON Schema for a content type bundle.
 *
 * Delegates to EntityJsonSchemaComposer to produce the schema document.
 * The response is consumed by the React frontend to resolve field labels,
 * types, and inline entity metadata for drafted content.
 */
class ContentSchemaController extends ControllerBase {

  /**
   * Constructs a ContentSchemaController.
   *
   * @param \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer $composer
   *   The JSON Schema composer service.
   */
  public function __construct(
    private readonly EntityJsonSchemaComposer $composer,
  ) {}

  /**
   * Returns the JSON Schema for the requested entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type machine name (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The composed schema as JSON.
   */
  public function get(string $entity_type_id, string $bundle): JsonResponse {
    $schema = $this->composer->compose($entity_type_id, $bundle);
    return new JsonResponse($schema);
  }

}
