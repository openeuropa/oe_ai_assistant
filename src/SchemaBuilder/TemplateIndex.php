<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;

/**
 * Answers what a drafting template says about one bundle.
 *
 * A template nests by field, so the fields of a paragraph sit under the
 * reference that reaches it. A builder sees only the bundle it is describing
 * and never the path taken to get there, so the tree is flattened by bundle
 * on the way in. A bundle named twice in one template therefore has one set
 * of fields and one set of defaults, whichever reference reached it.
 */
final class TemplateIndex {

  /**
   * Field names keyed by entity type and bundle.
   */
  private array $fields = [];

  /**
   * Default values keyed by entity type, bundle and field name.
   */
  private array $defaults = [];

  public function __construct(AiDraftingTemplateInterface $template) {
    $this->read('node', $template->getContentType(), $template->getFields(), $template->resolveDefaults());
  }

  /**
   * Checks whether the template asks for a field of a bundle.
   */
  public function wants(string $entityTypeId, string $bundle, string $fieldName): bool {
    return in_array($fieldName, $this->fields[$entityTypeId][$bundle] ?? [], TRUE);
  }

  /**
   * Returns the value the template pins on a field, or NULL when it pins none.
   */
  public function defaultFor(string $entityTypeId, string $bundle, string $fieldName): ?array {
    return $this->defaults[$entityTypeId][$bundle][$fieldName] ?? NULL;
  }

  /**
   * Checks whether the template describes a bundle at all.
   */
  public function covers(string $entityTypeId, string $bundle): bool {
    return isset($this->fields[$entityTypeId][$bundle]);
  }

  /**
   * Walks one level of the template and the reference items below it.
   */
  private function read(string $entityTypeId, string $bundle, array $fields, array $defaults): void {
    $this->fields[$entityTypeId][$bundle] = array_merge(
      $this->fields[$entityTypeId][$bundle] ?? [],
      array_keys($fields),
      array_keys($defaults),
    );

    foreach ($defaults as $fieldName => $default) {
      $this->defaults[$entityTypeId][$bundle][$fieldName] = $default['default_value'] ?? $default;
    }

    foreach ($fields as $field) {
      foreach ($field['items'] ?? [] as $item) {
        $this->read(
          $item['entity_type'],
          $item['bundle'],
          $item['fields'] ?? [],
          $item['defaults'] ?? [],
        );
      }
    }
  }

}
