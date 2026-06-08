<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

/**
 * Holds the result of an AI drafting template validation run.
 */
final class TemplateValidationResult {

  /**
   * Validation errors keyed by category.
   *
   * @var array<string, string[]>
   */
  private array $errors = [];

  /**
   * Adds a validation error.
   *
   * @param string $message
   *   The validation error message.
   * @param string $category
   *   The category the error belongs to.
   */
  public function addError(string $message, string $category = 'fields'): void {
    $this->errors[$category][] = $message;
  }

  /**
   * Checks whether the validation result has no errors.
   *
   * @return bool
   *   TRUE when no errors were collected, FALSE otherwise.
   */
  public function isValid(): bool {
    return empty($this->getErrors());
  }

  /**
   * Returns errors, optionally filtered to a single category.
   *
   * Pass a category (e.g. 'fields', 'defaults', 'content_type') to get only
   * those errors. Omit to get all errors from every category, flattened.
   *
   * @param string|null $category
   *   The category to filter by, or NULL to return all errors.
   *
   * @return string[]
   *   The validation error messages.
   */
  public function getErrors(?string $category = NULL): array {
    if ($category !== NULL) {
      return $this->errors[$category] ?? [];
    }
    return $this->errors ? array_merge(...array_values($this->errors)) : [];
  }

}
