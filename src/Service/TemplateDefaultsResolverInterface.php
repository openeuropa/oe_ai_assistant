<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Resolves the default values a drafting template declares for a bundle.
 */
interface TemplateDefaultsResolverInterface {

  /**
   * Resolves tokens and entity references across a defaults map.
   *
   * Replaces the __NOW__ token with the request time and each target_uuid
   * with the target_id of the entity it names. Fields the bundle does not
   * have are returned unchanged.
   *
   * @param array $defaults
   *   Default definitions keyed by field name, each holding a 'default_value'
   *   list, as stored on the template.
   * @param string $entityTypeId
   *   The entity type the fields belong to.
   * @param string $bundle
   *   The bundle the fields belong to.
   *
   * @return array
   *   The defaults map with tokens and references resolved.
   *
   * @throws \RuntimeException
   *   If a target_uuid does not name an existing entity.
   */
  public function resolve(array $defaults, string $entityTypeId, string $bundle): array;

  /**
   * Resolves the entity references of one field's default value list.
   *
   * @param array $defaultValue
   *   The 'default_value' list of a single field default.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field the default applies to.
   * @param string $entityTypeId
   *   The entity type the field belongs to.
   * @param string $bundle
   *   The bundle the field belongs to.
   *
   * @return array
   *   The list with each target_uuid replaced by the matching target_id.
   *
   * @throws \RuntimeException
   *   If a target_uuid does not name an existing entity.
   */
  public function resolveFieldDefaultValue(array $defaultValue, FieldDefinitionInterface $fieldDefinition, string $entityTypeId, string $bundle): array;

}
