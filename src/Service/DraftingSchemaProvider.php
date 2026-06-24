<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;

/**
 * Resolves a drafting template and returns the (template-pruned) field groups.
 */
class DraftingSchemaProvider implements DraftingSchemaProviderInterface {

  public function __construct(
    private readonly EntityJsonSchemaComposer $composer,
    private readonly TemplateSchemaFilterInterface $filter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function groups(string $entityTypeId, string $bundle, string $templateId = ''): array {
    $template = $this->loadTemplate($templateId, $bundle);
    if ($template === NULL) {
      return $this->composer->splitSchemaIntoGroups($entityTypeId, $bundle);
    }
    $schema = $this->composer->compose($entityTypeId, $bundle);
    return $this->filter->splitIntoGroups($schema, $template);
  }

  /**
   * Loads the template for a bundle, or NULL to fall back to the full schema.
   *
   * Returns NULL when no id is given, the template is missing, or it targets a
   * different content type.
   *
   * @param string $templateId
   *   The id of the template.
   * @param string $bundle
   *   The id of the bundle.
   *
   * @return \Drupal\oe_ai_assistant\AiDraftingTemplateInterface|null
   *   Returns the template or NULL if none found with the given id.
   */
  private function loadTemplate(string $templateId, string $bundle): ?AiDraftingTemplateInterface {
    if ($templateId === '') {
      return NULL;
    }
    $template = $this->entityTypeManager
      ->getStorage('ai_drafting_template')
      ->load($templateId);
    return $template instanceof AiDraftingTemplateInterface
      && $template->getContentType() === $bundle
        ? $template
        : NULL;
  }

}
