<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\oe_ai_assistant\TemplateValidationResult;

/**
 * Thrown by AiDraftingTemplate::preSave() when template validation fails.
 *
 * Extends EntityStorageException so it is caught by any code that already
 * handles storage-layer failures, while also carrying the structured
 * TemplateValidationResult for callers that need the individual error messages.
 */
class TemplateValidationException extends EntityStorageException {

  /**
   * Constructs a new TemplateValidationException.
   *
   * @param string $templateId
   *   The machine name of the template that failed validation.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result carrying the individual error messages.
   */
  public function __construct(
    private readonly string $templateId,
    private readonly TemplateValidationResult $result,
  ) {
    $errors = implode("\n- ", $result->getErrors());
    parent::__construct("AI drafting template '$templateId' is invalid:\n- $errors");
  }

  /**
   * Returns the machine name of the template that failed validation.
   */
  public function getTemplateId(): string {
    return $this->templateId;
  }

  /**
   * Returns the structured validation result with individual error messages.
   */
  public function getResult(): TemplateValidationResult {
    return $this->result;
  }

}
