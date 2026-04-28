<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

/**
 * Holds the result of an AI drafting template validation run.
 */
final class TemplateValidationResult {

  /** @var array<string, string[]> */
  private array $errors = [];

  public function addError(string $message, string $category = 'fields'): void {
    $this->errors[$category][] = $message;
  }

  public function isValid(): bool {
    return empty($this->getErrors());
  }

  /**
   * Returns errors, optionally filtered to a single category.
   *
   * Pass a category (e.g. 'fields', 'defaults', 'content_type') to get only
   * those errors. Omit to get all errors from every category, flattened.
   *
   * @return string[]
   */
  public function getErrors(?string $category = NULL): array {
    if ($category !== NULL) {
      return $this->errors[$category] ?? [];
    }
    return $this->errors ? array_merge(...array_values($this->errors)) : [];
  }

}
