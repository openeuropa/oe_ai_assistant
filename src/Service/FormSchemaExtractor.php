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
 * Unlike ContentTypeSchemaExtractor which describes the data model (field
 * types, cardinalities, storage constraints), this service describes the
 * editorial experience: which widget the editor uses, the interaction mode
 * the LLM should apply, form groups and tabs from the field_group module,
 * and UI-level constraints such as maxlength enforced by JavaScript.
 *
 * The output is organised around the form display configuration
 * (entity_form_display) rather than raw field definitions. Fields that are
 * hidden from the form (not in any display component) are excluded, as are
 * fields with widgets mapped to the 'skip' interaction mode (language
 * selector, moderation state, path alias, etc.).
 *
 * When a field whitelist is configured via the
 * oe_ai_assistant.content_schema config object, only whitelisted fields
 * are included in the schema. This allows site builders to restrict the
 * fields the AI assistant can see per content type.
 */
class FormSchemaExtractor {

  /**
   * Maps widget plugin IDs to the interaction mode the LLM should apply.
   *
   * Interaction modes:
   * - draft: the editor creates or edits content inline; the LLM should
   *   generate a value for this field.
   * - draft_inline: an Inline Entity Form widget; the LLM should draft the
   *   nested entity's fields recursively.
   * - select: the editor picks from existing entities or a predefined list;
   *   the LLM can suggest but cannot create the referenced entity.
   * - skip: a system or hidden field; the LLM should ignore it completely.
   *
   * Widget plugin IDs not present in this map result in a NULL interaction,
   * which causes the field to be excluded from the schema.
   *
   * @var array<string, string>
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
    // Timestamp widgets are typically auto-set by Drupal; skip them.
    'datetime_timestamp' => 'skip',
    'daterange_default' => 'draft',
    // File/image -- editor uploads; LLM cannot produce binary content.
    'file_generic' => 'select',
    'image_image' => 'select',
    // System widgets that the LLM should never touch.
    'language_select' => 'skip',
    'moderation_state_default' => 'skip',
    'path' => 'skip',
  ];

  /**
   * Maps widget plugin IDs to simplified widget category labels.
   *
   * These category strings give the LLM a concise hint about the
   * expected input format without exposing Drupal-internal plugin IDs.
   * Widget IDs not in this map fall through to the raw plugin ID string.
   *
   * @var array<string, string>
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

  /**
   * Constructs a new FormSchemaExtractor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Used to load entity_form_display config entities.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Used to load field definitions for type and settings inspection.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used to resolve bundle labels and enumerate allowed bundles on
   *   inline entity form fields.
   * @param \Drupal\oe_ai_assistant\Service\ContentTypeSchemaExtractor $contentTypeSchemaExtractor
   *   Used to access the shared TYPE_MAP constant for type normalisation.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Used to read the oe_ai_assistant.content_schema configuration, which
   *   may restrict which fields are exposed per content type.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly ContentTypeSchemaExtractor $contentTypeSchemaExtractor,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Extracts the form schema for the content type of the given node.
   *
   * Convenience wrapper around extract() that derives entity type and bundle
   * from an already-loaded node object.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose content type form schema should be extracted.
   * @param int $maxDepth
   *   Maximum recursion depth for inline entity form fields.
   *
   * @return array
   *   Form schema array with 'contentType', 'label', and 'groups' keys.
   */
  public function extractFromNode(NodeInterface $node, int $maxDepth = 3): array {
    return $this->extract('node', $node->bundle(), $maxDepth);
  }

  /**
   * Extracts the form schema for a given entity type and bundle.
   *
   * Loads the default form display configuration for the entity type and
   * bundle, iterates over its components, filters based on the widget
   * interaction map and any configured field whitelist, then organises the
   * resulting field schemas into the form's tab/group hierarchy.
   *
   * When no form display configuration exists an empty groups array is
   * returned so callers can handle the "no form" case gracefully.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node', 'paragraph').
   * @param string $bundle
   *   The bundle machine name (e.g. 'article', 'text_with_image').
   * @param int $maxDepth
   *   Maximum recursion depth for inline entity form fields. Decrement by
   *   one on each nested call to prevent infinite recursion on circular
   *   entity reference configurations.
   *
   * @return array
   *   Associative array with keys:
   *   - contentType (string): the bundle machine name.
   *   - label (string): the human-readable bundle label.
   *   - groups (array): list of tab/group arrays, each with 'label' and
   *     'fields'. Fields within groups may themselves contain nested group
   *     arrays for sub-group form sections.
   */
  public function extract(string $entityTypeId, string $bundle, int $maxDepth = 3): array {
    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    // Cast to string: labels may be TranslatableMarkup objects.
    $label = $bundleInfo[$bundle]['label'] ?? $bundle;

    // Load the default form display. If none exists (e.g. a bundle with no
    // configured display), return an empty schema so the caller does not
    // need to handle NULL.
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

    // getComponents() returns all visible form components (widget configs).
    $components = $formDisplay->getComponents();
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    // Extract field_group third-party settings into a structured hierarchy.
    $groups = $this->extractGroups($formDisplay);
    // NULL means no whitelist configured; all visible fields are included.
    $whitelist = $this->getFieldWhitelist($bundle);

    // Build field schemas enriched with form display info.
    $fields = [];
    foreach ($components as $fieldName => $component) {
      // If a whitelist is configured, only include whitelisted fields.
      if ($whitelist !== NULL && !in_array($fieldName, $whitelist, TRUE)) {
        continue;
      }

      // Skip pseudo-components (layout builders, form actions, etc.) that
      // have no corresponding field definition.
      if (!isset($fieldDefinitions[$fieldName])) {
        continue;
      }

      $fieldDef = $fieldDefinitions[$fieldName];

      // Computed fields derive their value from other data and cannot be
      // set by an editor or an AI; exclude them.
      if ($fieldDef->isComputed()) {
        continue;
      }

      $widgetType = $component['type'] ?? '';
      $interaction = self::WIDGET_INTERACTION_MAP[$widgetType] ?? NULL;

      // Skip fields with no known interaction (unknown widget plugin) or
      // those explicitly mapped to 'skip' (language selector, path, etc.).
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

      // Key by field name for fast group membership lookups below.
      $fields[$fieldName] = $field;
    }

    // Organise the flat field map into the form's tab/group hierarchy.
    $organizedGroups = $this->organizeFieldsIntoGroups($groups, $fields);

    return [
      'contentType' => $bundle,
      'label' => (string) $label,
      'groups' => $organizedGroups,
    ];
  }

  /**
   * Builds the schema for a single field with form display information.
   *
   * Combines data from three sources:
   * 1. The field storage definition (Drupal type, cardinality).
   * 2. The field instance definition (label, required, description).
   * 3. The form display component (widget type, widget settings,
   *    third-party settings such as the maxlength module).
   *
   * For 'draft_inline' interaction fields, resolveInlineForm() is called
   * to add the nested form structure. For 'select' reference fields,
   * resolveReferenceTarget() adds target entity type and bundle info.
   *
   * @param string $fieldName
   *   The field machine name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The field definition.
   * @param array $component
   *   The form display component configuration array (type, weight,
   *   settings, third_party_settings, region).
   * @param string $interaction
   *   The resolved interaction mode from WIDGET_INTERACTION_MAP.
   * @param int $maxDepth
   *   Remaining recursion depth passed to resolveInlineForm().
   *
   * @return array
   *   Field schema array with at minimum: name, label, type, widget,
   *   interaction, required, cardinality, description.
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
    // Third-party settings added by contrib modules (e.g. maxlength).
    $thirdParty = $component['third_party_settings'] ?? [];

    $schema = [
      'name' => $fieldName,
      'label' => (string) $fieldDef->getLabel(),
      // Map Drupal type to the same normalised type strings used by
      // ContentTypeSchemaExtractor so consumers have a consistent contract.
      'type' => ContentTypeSchemaExtractor::TYPE_MAP[$drupalType] ?? 'unknown',
      // Map widget plugin ID to a simplified category label.
      'widget' => self::WIDGET_TYPE_MAP[$widgetType] ?? $widgetType,
      'interaction' => $interaction,
      'required' => $fieldDef->isRequired(),
      // Cardinality: -1 means unlimited, positive integer means fixed max.
      'cardinality' => $storageDef->getCardinality(),
      'description' => (string) ($fieldDef->getDescription() ?? ''),
    ];

    // The maxlength module stores its JS character counter limit in
    // third-party settings. This takes precedence over the storage-level
    // max_length because it reflects what the editor UI enforces.
    if (!empty($thirdParty['maxlength']['maxlength_js'])) {
      $schema['maxLength'] = (int) $thirdParty['maxlength']['maxlength_js'];
    }
    elseif ($drupalType === 'string') {
      // Fall back to the database column width for plain string fields.
      $maxLength = $storageDef->getSettings()['max_length'] ?? NULL;
      if ($maxLength) {
        $schema['maxLength'] = (int) $maxLength;
      }
    }

    // Extract allowed values for list_* field types displayed with a select
    // or radio widget. The LLM uses these to pick valid option values.
    if (in_array($drupalType, ['list_string', 'list_integer', 'list_float'], TRUE)) {
      $allowedValues = $storageDef->getSettings()['allowed_values'] ?? [];
      if ($allowedValues) {
        $schema['allowedValues'] = $allowedValues;
      }
    }

    // For inline entity form widgets, resolve the nested form structure
    // so the LLM knows what fields to draft for each paragraph bundle.
    if ($interaction === 'draft_inline') {
      $schema['inlineForm'] = $this->resolveInlineForm(
        $fieldDef,
        $widgetSettings,
        $maxDepth,
      );
    }

    // For select/autocomplete reference widgets, add target type and bundle
    // info so the LLM knows what kind of entity the editor must select.
    if ($interaction === 'select' && in_array($drupalType, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $schema['reference'] = $this->resolveReferenceTarget($fieldDef);
    }

    return $schema;
  }

  /**
   * Resolves inline entity form fields recursively.
   *
   * Reads the target entity type and allowed bundles from the field
   * definition, then -- if depth allows it -- recursively calls extract()
   * on each bundle to obtain its own form schema. The result describes
   * the complete nested form structure the editor sees in the Inline
   * Entity Form widget.
   *
   * When depth is 1 (the leaf level) only bundle labels are included
   * without their nested field lists. This prevents infinite recursion on
   * circular entity reference configurations.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The inline entity form field definition.
   * @param array $widgetSettings
   *   The widget's settings array from the form display component.
   *   Used to read allow_new and allow_existing flags.
   * @param int $depth
   *   Remaining recursion depth. Decremented by 1 on each recursive call.
   *
   * @return array
   *   Array with keys:
   *   - targetType (string): target entity type ID.
   *   - allowNew (bool): whether new entities can be created inline.
   *   - allowExisting (bool): whether existing entities can be selected.
   *   - targetBundles (array): map of bundle machine name to bundle data.
   *     At depth > 1 each bundle has 'label' and 'groups' (form schema).
   *     At depth 1 each bundle has 'label' only.
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

    // When target_bundles is NULL the handler allows all bundles.
    if ($targetBundles === NULL) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      // Flatten the associative bundle array to a simple list.
      $targetBundles = array_values($targetBundles);
    }

    $result = [
      'targetType' => $targetType,
      // Widget settings control whether the editor can create new entities
      // inline or must reuse existing ones.
      'allowNew' => $widgetSettings['allow_new'] ?? TRUE,
      'allowExisting' => $widgetSettings['allow_existing'] ?? FALSE,
      'targetBundles' => [],
    ];

    // At the depth limit, emit only labels to avoid infinite recursion.
    if ($depth <= 1) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      foreach ($targetBundles as $b) {
        $result['targetBundles'][$b] = [
          'label' => (string) ($bundleInfo[$b]['label'] ?? $b),
        ];
      }
      return $result;
    }

    // Recurse into each target bundle's form display to get its full schema.
    foreach ($targetBundles as $targetBundle) {
      $bundleSchema = $this->extract($targetType, $targetBundle, $depth - 1);
      $result['targetBundles'][$targetBundle] = [
        'label' => $bundleSchema['label'],
        // Include the nested group/field structure for the LLM.
        'groups' => $bundleSchema['groups'],
      ];
    }

    return $result;
  }

  /**
   * Resolves reference target info for select/autocomplete widgets.
   *
   * Produces a compact summary of what the editor will be picking when
   * they interact with the reference field: the target entity type and
   * the list of allowed bundle labels. The LLM uses this to describe
   * the expected value in its response to the editor.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The reference field definition.
   *
   * @return array
   *   Array with keys:
   *   - targetType (string): target entity type ID.
   *   - targetBundles (array): map of bundle machine name to array with
   *     a single 'label' key.
   */
  private function resolveReferenceTarget(FieldDefinitionInterface $fieldDef): array {
    $storageDef = $fieldDef->getFieldStorageDefinition();
    $targetType = $storageDef->getSetting('target_type');
    $handlerSettings = $fieldDef->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;

    // Enumerate all bundles when no restriction is configured.
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
   *
   * The field_group module stores its configuration as third-party settings
   * on the form display entity. Each group entry contains the group label,
   * its parent group, its children (field names or sub-group names), the
   * display format (tabs, tab, details, etc.), and a sort weight.
   *
   * Returns an empty array when the field_group module is not active or
   * no groups are configured for this form display.
   *
   * @param mixed $formDisplay
   *   The loaded entity_form_display config entity. Typed as mixed because
   *   Drupal's form display interface is not imported here.
   *
   * @return array
   *   Map of group machine name to group config array with keys:
   *   label, parent, children, format, weight.
   */
  private function extractGroups($formDisplay): array {
    // getThirdPartySettings() returns an empty array when the module has
    // not stored any settings, so no null-check is needed.
    $groupSettings = $formDisplay->getThirdPartySettings('field_group');
    $groups = [];

    foreach ($groupSettings as $groupName => $config) {
      $groups[$groupName] = [
        'label' => $config['label'] ?? $groupName,
        // parent_name is an empty string for top-level groups.
        'parent' => $config['parent_name'] ?? '',
        // Children is an ordered list of field names and sub-group names.
        'children' => $config['children'] ?? [],
        // format_type distinguishes tabs, tab, details, fieldset, etc.
        'format' => $config['format_type'] ?? '',
        'weight' => $config['weight'] ?? 0,
      ];
    }

    return $groups;
  }

  /**
   * Organizes fields into their form group hierarchy.
   *
   * Looks for a top-level 'tabs' group (a root container whose direct
   * children are individual tab elements). When found, iterates the tabs
   * and recursively collects each tab's fields (including nested sub-groups).
   * When no tab structure exists, returns all fields in a single flat
   * 'Content' group.
   *
   * Tabs that have no visible fields after whitelist/interaction filtering
   * are omitted from the result to keep the schema tidy.
   *
   * @param array $groups
   *   Group hierarchy from extractGroups().
   * @param array $fields
   *   Filtered field schema map (field name => schema array).
   *
   * @return array
   *   List of group arrays, each with 'label' and 'fields'. Fields entries
   *   may themselves be field arrays or nested group arrays (sub-groups
   *   within a tab).
   */
  private function organizeFieldsIntoGroups(array $groups, array $fields): array {
    // Find the root tab group: a top-level group with format 'tabs' and no
    // parent. This is the outermost container for the tab navigation.
    $rootGroup = '';
    foreach ($groups as $name => $group) {
      if ($group['format'] === 'tabs' && empty($group['parent'])) {
        $rootGroup = $name;
        break;
      }
    }

    if (empty($rootGroup) || empty($groups[$rootGroup]['children'])) {
      // No tab structure -- return a flat list under a generic 'Content' tab.
      return [
        [
          'label' => 'Content',
          'fields' => array_values($fields),
        ],
      ];
    }

    $result = [];
    // Iterate the root group's children in order; each child is a tab.
    foreach ($groups[$rootGroup]['children'] as $tabName) {
      $tab = $groups[$tabName] ?? NULL;
      if (!$tab) {
        continue;
      }

      // Recursively collect all fields that belong to this tab and its
      // sub-groups. Sub-groups appear as nested group objects within the
      // tab's field list.
      $tabFields = $this->collectFieldsFromGroup($tabName, $groups, $fields);
      // Skip tabs that have no visible fields after all filtering.
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
   *
   * Iterates the group's children list. Each child is either a field name
   * (present in $fields) or a sub-group name (present in $groups). Fields
   * are added directly; sub-groups are represented as nested objects with
   * their own label and fields array.
   *
   * @param string $groupName
   *   The machine name of the group to collect fields from.
   * @param array $groups
   *   Full group hierarchy from extractGroups().
   * @param array $fields
   *   Filtered field schema map (field name => schema array).
   *
   * @return array
   *   Ordered list of field arrays and/or nested group arrays. Returns an
   *   empty array when the group does not exist or has no visible children.
   */
  private function collectFieldsFromGroup(string $groupName, array $groups, array $fields): array {
    $group = $groups[$groupName] ?? NULL;
    if (!$group) {
      return [];
    }

    $collected = [];
    foreach ($group['children'] as $child) {
      if (isset($fields[$child])) {
        // Direct field member of this group.
        $collected[] = $fields[$child];
      }
      elseif (isset($groups[$child])) {
        // Sub-group: recurse to collect its fields, then wrap them with the
        // sub-group's label so the LLM knows the visual grouping context.
        $subFields = $this->collectFieldsFromGroup($child, $groups, $fields);
        if (!empty($subFields)) {
          $collected[] = [
            'group' => $groups[$child]['label'],
            'fields' => $subFields,
          ];
        }
      }
      // Children that are neither a known field nor a known group (e.g.
      // hidden/removed fields) are silently skipped.
    }

    return $collected;
  }

  /**
   * Returns the field whitelist for a bundle, or NULL if none is configured.
   *
   * When NULL is returned, all visible form fields are included in the
   * schema. When an array is returned, only fields whose machine names appear
   * in that array are included. This allows site builders to restrict what
   * the AI assistant can see and draft on a per-content-type basis.
   *
   * The whitelist is read from the immutable
   * oe_ai_assistant.content_schema configuration object under the key
   * "field_whitelist.{bundle}".
   *
   * @param string $bundle
   *   The content type machine name.
   *
   * @return string[]|null
   *   Array of whitelisted field machine names, or NULL when no restriction
   *   is configured for this bundle.
   */
  private function getFieldWhitelist(string $bundle): ?array {
    $config = $this->configFactory->get('oe_ai_assistant.content_schema');
    $whitelist = $config->get("field_whitelist.$bundle");

    // Return NULL rather than an empty array so callers can distinguish
    // "no restriction configured" from "restriction configured but empty".
    return is_array($whitelist) ? $whitelist : NULL;
  }

}
