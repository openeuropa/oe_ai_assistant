<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\Exception\DraftingTemplateException;
use Drupal\oe_ai_assistant\SchemaBuilder\TemplateIndex;
use Drupal\schema_builder\SchemaBuilderInterface;

/**
 * Resolves a drafting template and returns the field groups it asks for.
 */
class DraftingSchemaProvider implements DraftingSchemaProviderInterface {

  public function __construct(
    private readonly SchemaBuilderInterface $schemaApi,
    private readonly TemplateDefaultsResolverInterface $defaultsResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function groups(string $entityTypeId, string $bundle, ?string $templateId = NULL): array {
    $template = $this->resolveTemplate($entityTypeId, $bundle, $templateId);
    $parameters = $template === NULL
      ? []
      : ['template' => new TemplateIndex($this->defaultsResolver, $template)];

    $schema = $this->schemaApi->get($entityTypeId, $bundle, $parameters);

    return $this->split($entityTypeId, $bundle, $schema);
  }

  /**
   * Splits a bundle schema into the groups the drafters work on.
   *
   * One drafter writes a whole group, so a group is as much as a single call
   * should carry. Every scalar field goes in one group, and each field that
   * holds entities of its own gets a group to itself, which keeps a long run
   * of paragraphs out of the call that writes the title.
   *
   * @return array
   *   Ordered groups, each with 'groupId', 'label', 'fieldNames' and
   *   'schemaSlice' keys.
   */
  private function split(string $entityTypeId, string $bundle, array $schema): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);

    $mainFields = [];
    $groups = [];
    foreach ($schema['properties'] ?? [] as $fieldName => $fieldSchema) {
      if (!isset($fieldSchema['items']['oneOf'])) {
        $mainFields[$fieldName] = $fieldSchema;
        continue;
      }
      $groups[] = [
        'groupId' => $fieldName,
        'label' => isset($definitions[$fieldName])
          ? (string) $definitions[$fieldName]->getLabel()
          : $fieldName,
        'fieldNames' => [$fieldName],
        'schemaSlice' => $this->slice([$fieldName => $fieldSchema]),
      ];
    }

    if ($mainFields !== []) {
      array_unshift($groups, [
        'groupId' => 'main_fields',
        'label' => 'Main fields',
        'fieldNames' => array_keys($mainFields),
        'schemaSlice' => $this->slice($mainFields),
      ]);
    }

    return $groups;
  }

  /**
   * Wraps the fields of one group in a schema a drafter can be given.
   */
  private function slice(array $properties): array {
    return [
      'type' => 'object',
      'properties' => $properties,
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function availableTemplates(string $bundle): array {
    $templates = $this->entityTypeManager
      ->getStorage('ai_drafting_template')
      ->loadByProperties(['content_type' => $bundle, 'status' => TRUE]);
    ksort($templates);
    return array_values(array_map(
      static fn (AiDraftingTemplateInterface $template): array => [
        'id' => (string) $template->id(),
        'label' => (string) $template->label(),
        'description' => $template->getDescription(),
      ],
      $templates,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function resolveTemplate(string $entityTypeId, string $bundle, ?string $templateId = NULL): ?AiDraftingTemplateInterface {
    if ($entityTypeId !== 'node') {
      if ($templateId !== NULL && $templateId !== '') {
        throw new DraftingTemplateException('Drafting templates only apply to node bundles.');
      }
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('ai_drafting_template');

    if ($templateId !== NULL && $templateId !== '') {
      $template = $storage->load($templateId);
      if (!$template instanceof AiDraftingTemplateInterface) {
        throw new DraftingTemplateException(sprintf('Drafting template "%s" not found.', $templateId));
      }
      if (!$template->status()) {
        throw new DraftingTemplateException(sprintf('Drafting template "%s" is disabled.', $templateId));
      }
      if ($template->getContentType() !== $bundle) {
        throw new DraftingTemplateException(sprintf(
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
