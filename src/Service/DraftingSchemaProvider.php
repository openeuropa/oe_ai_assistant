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
  public function groups(string $entityTypeId, string $bundle, ?string $templateId = NULL): array {
    $template = $this->resolveTemplate($entityTypeId, $bundle, $templateId);
    if ($template === NULL) {
      return $this->composer->splitSchemaIntoGroups($entityTypeId, $bundle);
    }
    $schema = $this->composer->compose($entityTypeId, $bundle);
    return $this->filter->splitIntoGroups($schema, $template);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveTemplate(string $entityTypeId, string $bundle, ?string $templateId = NULL): ?AiDraftingTemplateInterface {
    if ($entityTypeId !== 'node') {
      if ($templateId !== NULL && $templateId !== '') {
        throw new \InvalidArgumentException('Drafting templates only apply to node bundles.');
      }
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('ai_drafting_template');

    if ($templateId !== NULL && $templateId !== '') {
      $template = $storage->load($templateId);
      if (!$template instanceof AiDraftingTemplateInterface) {
        throw new \InvalidArgumentException(sprintf('Drafting template "%s" not found.', $templateId));
      }
      if (!$template->status()) {
        throw new \InvalidArgumentException(sprintf('Drafting template "%s" is disabled.', $templateId));
      }
      if ($template->getContentType() !== $bundle) {
        throw new \InvalidArgumentException(sprintf(
          'Drafting template "%s" targets content type "%s", not "%s".',
          $templateId,
          $template->getContentType(),
          $bundle,
        ));
      }
      return $template;
    }

    // No id given: use the latest enabled template for the bundle (highest id).
    $candidates = $storage->loadByProperties([
      'content_type' => $bundle,
      'status' => TRUE,
    ]);
    if ($candidates === []) {
      return NULL;
    }
    ksort($candidates);
    return end($candidates);
  }

}
