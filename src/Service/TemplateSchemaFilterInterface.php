<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;

/**
 * Reduces a composed entity schema to the fields a drafting template allows.
 *
 * Works on the schema produced by EntityJsonSchemaComposer, which is never
 * modified.
 */
interface TemplateSchemaFilterInterface {

  /**
   * Reduces a schema to the fields the template lists.
   *
   * @param array $schema
   *   A composed schema from EntityJsonSchemaComposer.
   * @param \Drupal\oe_ai_assistant\AiDraftingTemplateInterface $template
   *   The template that defines which fields to keep.
   *
   * @return array
   *   The reduced schema.
   */
  public function filter(array $schema, AiDraftingTemplateInterface $template): array;

  /**
   * Reduces a schema and splits it into ordered groups for sub-agents.
   *
   * @param array $schema
   *   A composed schema from EntityJsonSchemaComposer.
   * @param \Drupal\oe_ai_assistant\AiDraftingTemplateInterface $template
   *   The template that defines which fields to keep.
   *
   * @return array
   *   Ordered groups, each with 'groupId', 'label', 'fieldNames', and
   *   'schemaSlice' keys.
   */
  public function splitIntoGroups(array $schema, AiDraftingTemplateInterface $template): array;

}
