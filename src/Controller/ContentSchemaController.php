<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Service\ContentTypeSchemaExtractor;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns content type field schemas as JSON responses.
 *
 * Handles the content-schema REST endpoint. Depending on the ?mode= query
 * parameter it delegates extraction to either ContentTypeSchemaExtractor
 * (raw data model) or FormSchemaExtractor (form-aware representation).
 *
 * The response is consumed by the React frontend to build context-aware
 * prompts and to render field-level AI suggestions in the correct order
 * and with the correct interaction affordances.
 */
class ContentSchemaController extends ControllerBase {

  /**
   * Constructs a ContentSchemaController instance.
   *
   * @param \Drupal\oe_ai_assistant\Service\ContentTypeSchemaExtractor $schemaExtractor
   *   Extracts the raw data-model schema for an entity type and bundle.
   *   Returns field names, types, cardinality, constraints, and entity
   *   reference targets without any form-layer information.
   * @param \Drupal\oe_ai_assistant\Service\FormSchemaExtractor $formSchemaExtractor
   *   Extracts a form-aware schema for an entity type and bundle.
   *   Enriches the raw schema with widget types, tab groupings, and
   *   interaction modes derived from the entity form display settings.
   */
  public function __construct(
    private readonly ContentTypeSchemaExtractor $schemaExtractor,
    private readonly FormSchemaExtractor $formSchemaExtractor,
  ) {}

  /**
   * Returns the field schema for the requested entity type and bundle.
   *
   * Reads the optional ?mode= query parameter and dispatches to the
   * appropriate extractor service. An unknown mode value falls through to
   * the default (form) extractor so that the frontend is always given a
   * usable response.
   *
   * @param string $entity_type_id
   *   The Drupal entity type machine name (e.g. "node", "media").
   *   Sourced from the {entity_type_id} route placeholder.
   * @param string $bundle
   *   The bundle machine name within the entity type (e.g. "article",
   *   "page"). Sourced from the {bundle} route placeholder.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request. The ?mode= query parameter controls which
   *   extractor is used:
   *     - "data"  -> ContentTypeSchemaExtractor (raw field definitions)
   *     - "form"  -> FormSchemaExtractor (form-aware schema, default)
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the extracted schema array.
   *   HTTP 200 on success. The schema structure varies by mode; see the
   *   respective extractor services for shape details.
   */
  public function get(string $entity_type_id, string $bundle, Request $request): JsonResponse {
    // Read the ?mode= query parameter, defaulting to "form" when absent.
    // The "form" default is intentional: the frontend primarily needs the
    // form-aware schema for rendering field suggestions in the correct order.
    $mode = $request->query->get('mode', 'form');

    // Dispatch to the appropriate extractor based on the requested mode.
    // The match expression falls through to FormSchemaExtractor for any
    // unrecognised mode value, ensuring the endpoint never returns an error
    // for a missing mode branch.
    $schema = match ($mode) {
      // Raw data-model schema: field types, cardinality, constraints, and
      // entity reference targets with no form-display information.
      'data' => $this->schemaExtractor->extract($entity_type_id, $bundle),
      // Form-aware schema (default): same field coverage as "data" plus
      // widget types, tab groups, and interaction modes derived from the
      // entity form display configuration.
      default => $this->formSchemaExtractor->extract($entity_type_id, $bundle),
    };

    return new JsonResponse($schema);
  }

}
