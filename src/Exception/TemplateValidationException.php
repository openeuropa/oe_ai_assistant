<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

use Drupal\Core\Entity\EntityStorageException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Thrown by AiDraftingTemplate::preSave() when template validation fails.
 *
 * Extends EntityStorageException so it is caught by any code that already
 * handles storage-layer failures, while also carrying the structured
 * constraint violation list for callers that need individual errors.
 */
class TemplateValidationException extends EntityStorageException {

  /**
   * Constructs a new TemplateValidationException.
   *
   * @param string $templateId
   *   The machine name of the template that failed validation.
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface $result
   *   The validation violations.
   */
  public function __construct(
    private readonly string $templateId,
    private readonly ConstraintViolationListInterface $result,
  ) {
    $errors = [];
    foreach ($result as $violation) {
      $errors[] = (string) $violation->getMessage();
    }
    parent::__construct("AI drafting template '$templateId' is invalid:\n- " . implode("\n- ", $errors));
  }

  /**
   * Returns the machine name of the template that failed validation.
   */
  public function getTemplateId(): string {
    return $this->templateId;
  }

  /**
   * Returns the structured validation result with individual violations.
   */
  public function getResult(): ConstraintViolationListInterface {
    return $this->result;
  }

}
