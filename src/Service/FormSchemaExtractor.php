<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\node\NodeInterface;

/**
 * Extracts a form-aware schema describing what an editor sees and can edit.
 *
 * Unlike ContentTypeSchemaExtractor which describes the data model, this
 * service describes the editorial experience: widget types, interaction modes
 * (draft inline, select existing, skip), form groups/tabs, and UI constraints
 * like maxlength enforced by JavaScript.
 */
class FormSchemaExtractor {

  /**
   * Maps widget types to an interaction mode the LLM can understand.
   *
   * - draft: the editor creates/edits content inline (LLM should generate)
   * - select: the editor picks from existing entities (LLM should suggest)
   * - skip: system/hidden field (LLM should ignore)
   */
  private const WIDGET_INTERACTION_MAP = [
    // Text input widgets -- LLM should draft content.
    'string_textfield' => 'draft',
    'string_textarea' => 'draft',
    'text_textarea' => 'draft',
    'text_textarea_with_summary' => 'draft',
    'text_textfield' => 'draft',
    'link_default' => 'draft',
    'link_with_reference' => 'draft',
    'email_default' => 'draft',
    'telephone_default' => 'draft',
    'number_integer' => 'draft',
    'number_decimal' => 'draft',
    'number_float' => 'draft',
    // Inline Entity Form -- LLM should draft the nested entity.
    'inline_entity_form_complex' => 'draft_inline',
    'inline_entity_form_simple' => 'draft_inline',
    // Entity browser -- editor selects existing content.
    'entity_browser_entity_reference' => 'select',
    // Autocomplete -- editor picks existing entity.
    'entity_reference_autocomplete' => 'select',
    'entity_reference_autocomplete_tags' => 'select',
    'skos_concept_entity_reference_autocomplete' => 'select',
    // Select/options -- editor picks from a list.
    'options_select' => 'select',
    'options_buttons' => 'select',
    'skos_concept_entity_reference_options_select' => 'select',
    // Boolean.
    'boolean_checkbox' => 'draft',
    // Date/time.
    'datetime_default' => 'draft',
    'datetime_timestamp' => 'skip',
    'daterange_default' => 'draft',
    // File/image -- editor uploads.
    'file_generic' => 'select',
    'image_image' => 'select',
    // System widgets.
    'language_select' => 'skip',
    'moderation_state_default' => 'skip',
    'path' => 'skip',
  ];

  /**
   * Maps widget types to a simpler widget category for the LLM.
   */
  private const WIDGET_TYPE_MAP = [
    'string_textfield' => 'textfield',
    'string_textarea' => 'textarea',
    'text_textarea' => 'textarea_formatted',
    'text_textarea_with_summary' => 'textarea_formatted_summary',
    'text_textfield' => 'textfield_formatted',
    'link_default' => 'link',
    'link_with_reference' => 'link',
    'boolean_checkbox' => 'checkbox',
    'datetime_default' => 'date',
    'daterange_default' => 'daterange',
    'options_select' => 'select',
    'options_buttons' => 'radios',
    'skos_concept_entity_reference_options_select' => 'select',
    'inline_entity_form_complex' => 'inline_form',
    'inline_entity_form_simple' => 'inline_form',
    'entity_browser_entity_reference' => 'entity_browser',
    'entity_reference_autocomplete' => 'autocomplete',
    'entity_reference_autocomplete_tags' => 'autocomplete',
    'skos_concept_entity_reference_autocomplete' => 'autocomplete',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly ContentTypeSchemaExtractor $contentTypeSchemaExtractor,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Extracts the form schema for the content type of the given node.
   */
  public function extractFromNode(NodeInterface $node, int $maxDepth = 3): array {
    return $this->extract('node', $node->bundle(), $maxDepth);
  }

  /**
   * Extracts the form schema for a given entity type and bundle.
   */
  public function extract(string $entityTypeId, string $bundle, int $maxDepth = 3): array {
    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    $label = $bundleInfo[$bundle]['label'] ?? $bundle;

    $formDisplay = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load("$entityTypeId.$bundle.default");

    if (!$formDisplay) {
      return [
        'contentType' => $bundle,
        'label' => (string) $label,
        'groups' => [],
      ];
    }

    $components = $formDisplay->getComponents();
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    $groups = $this->extractGroups($formDisplay);
    $whitelist = $this->getFieldWhitelist($bundle);

    // Build field schemas enriched with form display info.
    $fields = [];
    foreach ($components as $fieldName => $component) {
      // If a whitelist is configured, only include whitelisted fields.
      if ($whitelist !== NULL && !in_array($fieldName, $whitelist, TRUE)) {
        continue;
      }

      if (!isset($fieldDefinitions[$fieldName])) {
        continue;
      }

      $fieldDef = $fieldDefinitions[$fieldName];

      if ($fieldDef->isComputed()) {
        continue;
      }

      $widgetType = $component['type'] ?? '';
      $interaction = self::WIDGET_INTERACTION_MAP[$widgetType] ?? NULL;

      // Skip fields with no known interaction or explicitly skipped.
      if ($interaction === NULL || $interaction === 'skip') {
        continue;
      }

      $field = $this->buildFieldSchema(
        $fieldName,
        $fieldDef,
        $component,
        $interaction,
        $maxDepth,
      );

      $fields[$fieldName] = $field;
    }

    // Organize fields into groups.
    $organizedGroups = $this->organizeFieldsIntoGroups($groups, $fields);

    return [
      'contentType' => $bundle,
      'label' => (string) $label,
      'groups' => $organizedGroups,
    ];
  }

  /**
   * Builds the schema for a single field with form display information.
   */
  private function buildFieldSchema(
    string $fieldName,
    FieldDefinitionInterface $fieldDef,
    array $component,
    string $interaction,
    int $maxDepth,
  ): array {
    $storageDef = $fieldDef->getFieldStorageDefinition();
    $drupalType = $storageDef->getType();
    $widgetType = $component['type'] ?? '';
    $widgetSettings = $component['settings'] ?? [];
    $thirdParty = $component['third_party_settings'] ?? [];

    $schema = [
      'name' => $fieldName,
      'label' => (string) $fieldDef->getLabel(),
      'type' => ContentTypeSchemaExtractor::TYPE_MAP[$drupalType] ?? 'unknown',
      'widget' => self::WIDGET_TYPE_MAP[$widgetType] ?? $widgetType,
      'interaction' => $interaction,
      'required' => $fieldDef->isRequired(),
      'cardinality' => $storageDef->getCardinality(),
      'description' => (string) ($fieldDef->getDescription() ?? ''),
    ];

    // Extract maxlength from third-party settings (maxlength module).
    if (!empty($thirdParty['maxlength']['maxlength_js'])) {
      $schema['maxLength'] = (int) $thirdParty['maxlength']['maxlength_js'];
    }
    elseif ($drupalType === 'string') {
      $maxLength = $storageDef->getSettings()['max_length'] ?? NULL;
      if ($maxLength) {
        $schema['maxLength'] = (int) $maxLength;
      }
    }

    // Extract allowed values for select widgets.
    if (in_array($drupalType, ['list_string', 'list_integer', 'list_float'], TRUE)) {
      $allowedValues = $storageDef->getSettings()['allowed_values'] ?? [];
      if ($allowedValues) {
        $schema['allowedValues'] = $allowedValues;
      }
    }

    // For inline entity form widgets, resolve the nested form structure.
    if ($interaction === 'draft_inline') {
      $schema['inlineForm'] = $this->resolveInlineForm(
        $fieldDef,
        $widgetSettings,
        $maxDepth,
      );
    }

    // For select/autocomplete reference widgets, add target info.
    if ($interaction === 'select' && in_array($drupalType, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $schema['reference'] = $this->resolveReferenceTarget($fieldDef);
    }

    return $schema;
  }

  /**
   * Resolves inline entity form fields recursively.
   */
  private function resolveInlineForm(
    FieldDefinitionInterface $fieldDef,
    array $widgetSettings,
    int $depth,
  ): array {
    $storageDef = $fieldDef->getFieldStorageDefinition();
    $targetType = $storageDef->getSetting('target_type');
    $handlerSettings = $fieldDef->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;

    if ($targetBundles === NULL) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      $targetBundles = array_values($targetBundles);
    }

    $result = [
      'targetType' => $targetType,
      'allowNew' => $widgetSettings['allow_new'] ?? TRUE,
      'allowExisting' => $widgetSettings['allow_existing'] ?? FALSE,
      'targetBundles' => [],
    ];

    if ($depth <= 1) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      foreach ($targetBundles as $b) {
        $result['targetBundles'][$b] = [
          'label' => (string) ($bundleInfo[$b]['label'] ?? $b),
        ];
      }
      return $result;
    }

    // Recurse into each target bundle's form display.
    foreach ($targetBundles as $targetBundle) {
      $bundleSchema = $this->extract($targetType, $targetBundle, $depth - 1);
      $result['targetBundles'][$targetBundle] = [
        'label' => $bundleSchema['label'],
        'groups' => $bundleSchema['groups'],
      ];
    }

    return $result;
  }

  /**
   * Resolves reference target info for select/autocomplete widgets.
   */
  private function resolveReferenceTarget(FieldDefinitionInterface $fieldDef): array {
    $storageDef = $fieldDef->getFieldStorageDefinition();
    $targetType = $storageDef->getSetting('target_type');
    $handlerSettings = $fieldDef->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;

    if ($targetBundles === NULL) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      $targetBundles = array_values($targetBundles);
    }

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
    $resolved = [];
    foreach ($targetBundles as $b) {
      $resolved[$b] = ['label' => (string) ($bundleInfo[$b]['label'] ?? $b)];
    }

    return [
      'targetType' => $targetType,
      'targetBundles' => $resolved,
    ];
  }

  /**
   * Extracts field group hierarchy from the form display.
   */
  private function extractGroups($formDisplay): array {
    $groupSettings = $formDisplay->getThirdPartySettings('field_group');
    $groups = [];

    foreach ($groupSettings as $groupName => $config) {
      $groups[$groupName] = [
        'label' => $config['label'] ?? $groupName,
        'parent' => $config['parent_name'] ?? '',
        'children' => $config['children'] ?? [],
        'format' => $config['format_type'] ?? '',
        'weight' => $config['weight'] ?? 0,
      ];
    }

    return $groups;
  }

  /**
   * Organizes fields into their form group hierarchy.
   *
   * Returns only top-level groups (tabs) with fields nested inside.
   */
  private function organizeFieldsIntoGroups(array $groups, array $fields): array {
    // Find the root tab group (format=tabs).
    $rootGroup = '';
    foreach ($groups as $name => $group) {
      if ($group['format'] === 'tabs' && empty($group['parent'])) {
        $rootGroup = $name;
        break;
      }
    }

    if (empty($rootGroup) || empty($groups[$rootGroup]['children'])) {
      // No tab structure -- return flat list.
      return [
        [
          'label' => 'Content',
          'fields' => array_values($fields),
        ],
      ];
    }

    $result = [];
    foreach ($groups[$rootGroup]['children'] as $tabName) {
      $tab = $groups[$tabName] ?? NULL;
      if (!$tab) {
        continue;
      }

      $tabFields = $this->collectFieldsFromGroup($tabName, $groups, $fields);
      if (empty($tabFields)) {
        continue;
      }

      $result[] = [
        'label' => $tab['label'],
        'fields' => $tabFields,
      ];
    }

    return $result;
  }

  /**
   * Recursively collects fields belonging to a group and its sub-groups.
   */
  private function collectFieldsFromGroup(string $groupName, array $groups, array $fields): array {
    $group = $groups[$groupName] ?? NULL;
    if (!$group) {
      return [];
    }

    $collected = [];
    foreach ($group['children'] as $child) {
      if (isset($fields[$child])) {
        $collected[] = $fields[$child];
      }
      elseif (isset($groups[$child])) {
        // Sub-group: collect its fields with a nested label.
        $subFields = $this->collectFieldsFromGroup($child, $groups, $fields);
        if (!empty($subFields)) {
          $collected[] = [
            'group' => $groups[$child]['label'],
            'fields' => $subFields,
          ];
        }
      }
    }

    return $collected;
  }

  /**
   * Returns the field whitelist for a bundle, or NULL if none is configured.
   *
   * When NULL is returned, all visible form fields are included.
   */
  private function getFieldWhitelist(string $bundle): ?array {
    $config = $this->configFactory->get('oe_ai_assistant.content_schema');
    $whitelist = $config->get("field_whitelist.$bundle");

    return is_array($whitelist) ? $whitelist : NULL;
  }

}
