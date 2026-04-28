<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\TemplateValidationResult;

/**
 * Provides template loading and validation for AI drafting templates.
 */
interface AiDraftingTemplateManagerInterface {

  /**
   * Returns all templates targeting a content type, regardless of status.
   *
   * @param string $content_type
   *   Node bundle machine name.
   *
   * @return \Drupal\oe_ai_assistant\AiDraftingTemplateInterface[]
   *   Keyed by template ID.
   */
  public function getTemplatesForContentType(string $content_type): array;

  /**
   * Loads a single template by ID.
   *
   * @throws \InvalidArgumentException
   *   If the template does not exist.
   */
  public function loadTemplate(string $templateId): AiDraftingTemplateInterface;

  /**
   * Runs Level-1 (structural) and Level-2 (Drupal field) validation.
   */
  public function validateTemplate(AiDraftingTemplateInterface $template): TemplateValidationResult;

  /**
   * Returns the template's defaults with special tokens resolved.
   *
   * Supported tokens:
   *   __NOW__ — replaced with the current Unix timestamp (integer).
   *
   * @param array<string, mixed> $defaults
   *   Raw defaults array from a template.
   *
   * @return array<string, mixed>
   *   Defaults with all recognised tokens substituted.
   */
  public function resolveDefaults(array $defaults): array;

}
