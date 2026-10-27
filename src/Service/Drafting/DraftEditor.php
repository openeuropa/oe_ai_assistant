<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation;
use JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies typed, individual-value edits without replacing generated output.
 */
class DraftEditor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DraftHistoryInterface $history,
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    private readonly EntityJsonSchemaComposer $composer,
    #[Autowire(service: 'plugin.manager.entity_reference_selection')]
    private readonly SelectionPluginManagerInterface $selectionManager,
    private readonly DraftEntityBuilder $entityBuilder,
    private readonly AccountProxyInterface $currentUser,
    private readonly SessionConversation $conversation,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Returns effective content and the schema for editing a stored version.
   */
  public function getDraft(AiEditorialSessionInterface $session, int $version): array {
    $draft = $this->history->getDraftContent($session, $version);
    if ($draft === NULL) {
      throw new ActionException('not_found', 'The draft was not found in this session.', 404);
    }

    // Resolve the draft's template, rather than the current composer choice.
    $fullProperties = $this->composer->compose('node', $session->getContentType())['properties'];
    $properties = $fullProperties;
    $template = NULL;
    if ($draft['templateId'] !== NULL) {
      try {
        $properties = [];
        foreach ($draft['context']['groups'] ?? $this->schemaProvider->groups('node', $session->getContentType(), $draft['templateId']) as $group) {
          $properties += $group['schemaSlice']['properties'] ?? [];
        }
        $template = $this->schemaProvider->resolveTemplate('node', $session->getContentType(), $draft['templateId']);
      }
      catch (\InvalidArgumentException $e) {
        throw new ActionException('invalid_request', $e->getMessage(), 400);
      }
    }
    foreach (['status', 'moderation_state', ...array_keys($template?->resolveDefaults() ?? [])] as $fieldName) {
      if (isset($fullProperties[$fieldName])) {
        $properties[$fieldName] ??= $fullProperties[$fieldName];
      }
      if (isset($properties[$fieldName])) {
        $properties[$fieldName]['readOnly'] = TRUE;
      }
    }
    $fields = $draft['fields'];
    foreach ($template?->resolveDefaults() ?? [] as $name => $default) {
      if (array_key_exists($name, $fields)) {
        $fields[$name] = $default['default_value'];
        if (isset($properties[$name])) {
          $properties[$name]['x-read-only-reason'] = 'Set by the drafting template.';
        }
      }
    }
    $entity = $session->getNode() ?? $this->entityTypeManager->getStorage('node')->create([
      'type' => $session->getContentType(),
      'uid' => $this->currentUser->id(),
    ]);
    $schema = $this->withFieldAccess(['type' => 'object', 'properties' => $properties], $entity);
    $schema['readOnly'] = !$session->access('update', $this->currentUser)
      || ($session->getNode() !== NULL
        ? !$session->getNode()->access('update', $this->currentUser)
        : !$this->currentUser->hasPermission('create ' . $session->getContentType() . ' content'));

    return [
      'fields' => $fields,
      'revision' => $draft['revision'],
      'schema' => $schema,
    ];
  }

  /**
   * Persists one scalar leaf and its edit history.
   */
  public function edit(AiEditorialSessionInterface $session, int $version, string $fieldName, array $path, mixed $value, int $expectedRevision): array {
    if (!$session->access('update', $this->currentUser)) {
      throw new ActionException('forbidden', 'You cannot edit this session.', 403);
    }
    $existingNode = $session->getNode();
    if ($existingNode !== NULL
      ? !$existingNode->access('update', $this->currentUser)
      : !$this->currentUser->hasPermission('create ' . $session->getContentType() . ' content')) {
      throw new ActionException('forbidden', 'You cannot edit this content.', 403);
    }

    $lockName = 'oe_ai_assistant:draft:' . $session->id();
    if (!$this->lock->acquire($lockName)) {
      throw new ActionException('conflict', 'This draft is being updated. Please try again.', 409);
    }
    try {
      $storage = $this->entityTypeManager->getStorage('neuron_message');
      $storage->resetCache();
      $draft = $this->getDraft($session, $version);
      if ($draft['revision'] !== $expectedRevision) {
        throw new ActionException('conflict', 'This draft has changed. Reload the draft before applying your edit.', 409);
      }
      if (!array_key_exists($fieldName, $draft['fields']) || !isset($draft['schema']['properties'][$fieldName])) {
        throw new ActionException('invalid_field', 'This field is not editable in this draft.', 400);
      }

      $fieldValue = $draft['fields'][$fieldName];
      $this->replaceValue($fieldValue, $draft['schema']['properties'][$fieldName], $path, $value);
      if ($fieldValue === $draft['fields'][$fieldName]) {
        return $draft;
      }
      $fields = $draft['fields'];
      $fields[$fieldName] = $fieldValue;
      try {
        $entity = $this->entityBuilder->fromLlmFields('node', $session->getContentType(), $fields);
      }
      catch (\InvalidArgumentException | \JsonException | ExceptionInterface $e) {
        throw new ActionException('invalid_value', 'The draft contains a value that cannot be processed.', 400);
      }
      $this->validateField($entity, $fieldName, $path);

      foreach ($this->conversation->rows($session, 'drafting') as $message) {
        $metadata = SessionConversation::decode($message, 'meta');
        if (($metadata['type'] ?? '') !== 'tool_call_result') {
          continue;
        }
        foreach ($metadata['tools'] ?? [] as $call) {
          $result = SessionConversation::decodeResult($call['result'] ?? NULL);
          if (($result['draft']['version'] ?? NULL) !== $version) {
            continue;
          }
          $transaction = $this->database->startTransaction();
          try {
            $metadata['draft_edits'][$version]['fields'][$fieldName] = $fieldValue;
            $metadata['draft_edits'][$version]['revision'] = $expectedRevision + 1;
            $metadata['draft_edits'][$version]['changes'][] = [
              'field' => $fieldName,
              'path' => $path,
              'uid' => (int) $this->currentUser->id(),
              'at' => time(),
            ];
            $message->set('meta', json_encode($metadata, JSON_THROW_ON_ERROR))->save();
          }
          catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
          }
          unset($transaction);
          return $this->getDraft($session, $version);
        }
      }
      throw new ActionException('not_found', 'The draft was not found in this session.', 404);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Selects an inline entity variant using its immutable bundle discriminator.
   */
  private function variant(array $schema, mixed $value): array {
    if (!isset($schema['oneOf'])) {
      return $schema;
    }
    foreach ($schema['oneOf'] as $variant) {
      if (in_array($variant['type'] ?? '', ['string', 'integer', 'number', 'boolean'], TRUE)) {
        return array_replace($schema, $variant);
      }
    }
    foreach ($schema['oneOf'] as $variant) {
      foreach ($variant['properties'] ?? [] as $key => $property) {
        $bundle = $property['items']['properties']['target_id']['const'] ?? NULL;
        if ($bundle !== NULL && ($value[$key][0]['target_id'] ?? NULL) === $bundle) {
          return $variant;
        }
      }
    }
    throw new ActionException('invalid_field', 'This inline entity type cannot be edited.', 400);
  }

  /**
   * Marks inaccessible fields, including nested inline fields, read-only.
   */
  private function withFieldAccess(array $schema, FieldableEntityInterface $entity): array {
    foreach (array_keys($schema['properties'] ?? []) as $name) {
      $property = &$schema['properties'][$name];
      if (!$entity->hasField($name) || !$entity->get($name)->access('edit', $this->currentUser)) {
        $property['readOnly'] = TRUE;
        unset($property);
        continue;
      }
      $items = $entity->get($name);
      $definition = $items->getFieldDefinition();
      $property['title'] = (string) $definition->getLabel();
      $type = $definition->getType();
      // Older draft snapshots predate editor hints. Their allowed structure
      // stays pinned; only the presentation metadata comes from the field.
      if (isset($property['items']['properties']['value'])) {
        if (in_array($type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
          $property['items']['properties']['value']['x-editor'] = 'richtext';
        }
        elseif ($type === 'string_long') {
          $property['items']['properties']['value']['x-editor'] = 'textarea';
        }
        $allowed = $definition->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
        if ($allowed !== [] && isset($property['items']['properties']['value']['enum'])) {
          $property['items']['properties']['value']['x-enum-labels'] = array_map(
            static fn ($key) => (string) ($allowed[$key] ?? $key),
            $property['items']['properties']['value']['enum'],
          );
        }
      }
      if (isset($property['items']['properties']['summary'])) {
        $property['items']['properties']['summary']['x-editor'] = 'textarea';
      }
      // Reference inputs offer permitted entities by label, rather than
      // requiring an editor to know internal Drupal identifiers.
      if (isset($property['items']['properties']['target_id'])) {
        $selection = $this->selectionManager->getSelectionHandler($items->getFieldDefinition(), $entity);
        $options = [];
        foreach ($selection->getReferenceableEntities(NULL, 'CONTAINS', 100) as $group) {
          $options += $group;
        }
        $target = &$property['items']['properties']['target_id'];
        $target['enum'] = array_map(
          static fn ($id) => ($target['type'] ?? '') === 'integer' ? (int) $id : (string) $id,
          array_keys($options),
        );
        $target['x-enum-labels'] = array_map(static fn ($label) => html_entity_decode(strip_tags((string) $label), ENT_QUOTES, 'UTF-8'), array_values($options));
        $target['readOnly'] = $options === [];
        unset($target);
      }
      // Inline variants share field permissions for their bundle. Use an
      // unsaved stub for each variant so empty lists also have metadata.
      if (isset($property['items']['x-targetType'])) {
        $targetType = $property['items']['x-targetType'];
        $bundleKey = $this->entityTypeManager->getDefinition($targetType)->getKey('bundle');
        if (isset($property['items']['oneOf'])) {
          foreach ($property['items']['oneOf'] as &$variant) {
            $bundle = $variant['properties'][$bundleKey]['items']['properties']['target_id']['const'] ?? NULL;
            if ($bundle !== NULL) {
              $stub = $this->entityTypeManager->getStorage($targetType)->create([$bundleKey => $bundle]);
              $variant = $this->withFieldAccess($variant, $stub);
            }
          }
        }
        elseif (!isset($property['items']['properties']['target_id']) && isset($property['items']['properties'])) {
          $bundle = $property['items']['properties'][$bundleKey]['items']['properties']['target_id']['const'] ?? NULL;
          if ($bundle !== NULL) {
            $stub = $this->entityTypeManager->getStorage($targetType)->create([$bundleKey => $bundle]);
            $property['items'] = $this->withFieldAccess($property['items'], $stub);
          }
        }
      }
      unset($property);
    }
    return $schema;
  }

  /**
   * Replaces only a schema-declared primitive, preserving every sibling.
   */
  private function replaceValue(mixed &$current, array $schema, array $path, mixed $value): void {
    if (!empty($schema['readOnly']) || array_key_exists('const', $schema)) {
      throw new ActionException('forbidden', 'This value is read-only.', 403);
    }
    $schema = $this->variant($schema, $current);
    if ($path === []) {
      if (!in_array($schema['type'] ?? '', ['string', 'integer', 'number', 'boolean'], TRUE) || ($value !== NULL && !is_scalar($value))) {
        throw new ActionException('invalid_value', 'Only individual field values can be edited.', 400);
      }
      // Clearing an optional value keeps its item and siblings intact. Drupal
      // field constraints below reject clearing a required field.
      if ($value === NULL) {
        $current = NULL;
        return;
      }
      $validator = new Validator();
      $validator->validate($value, json_decode(json_encode($schema)));
      if (!$validator->isValid()) {
        throw new ActionException('invalid_value', implode(' ', array_column($validator->getErrors(), 'message')), 400);
      }
      $current = $value;
      return;
    }
    $key = array_shift($path);
    if (($schema['type'] ?? '') === 'array') {
      if (!is_int($key) || $key < 0 || !is_array($current) || ($key >= count($current) && !($key === 0 && $current === [])) || $key >= ($schema['maxItems'] ?? PHP_INT_MAX)) {
        throw new ActionException('invalid_value', 'The field item does not exist.', 400);
      }
      $current[$key] ??= [];
      $this->replaceValue($current[$key], $schema['items'], $path, $value);
      return;
    }
    $protected = [
      'format', 'type', 'status', 'parent_id', 'parent_type',
      'parent_field_name', 'behavior_settings',
    ];
    if (($schema['type'] ?? '') !== 'object' || !is_string($key) || !isset($schema['properties'][$key]) || in_array($key, $protected, TRUE)) {
      throw new ActionException('invalid_field', 'This value cannot be edited.', 400);
    }
    if (!is_array($current)) {
      throw new ActionException('invalid_value', 'The field item is invalid.', 400);
    }
    $current[$key] ??= NULL;
    $this->replaceValue($current[$key], $schema['properties'][$key], $path, $value);
  }

  /**
   * Checks actual field access and Drupal constraints along the edited path.
   */
  private function validateField(FieldableEntityInterface $entity, string $fieldName, array $path): void {
    $items = $entity->get($fieldName);
    if (!$items->access('edit', $this->currentUser)) {
      throw new ActionException('forbidden', 'You cannot edit this field.', 403);
    }
    $violations = $items->validate();
    if ($violations->count() > 0) {
      throw new ActionException('invalid_value', (string) $violations[0]->getMessage(), 400);
    }
    $delta = array_shift($path);
    $childField = array_shift($path);
    $item = is_int($delta) ? $items->get($delta) : NULL;
    if ($item && $items->getFieldDefinition()->getSetting('target_type') && $item->entity instanceof FieldableEntityInterface && is_string($childField) && $item->entity->hasField($childField)) {
      $this->validateField($item->entity, $childField, $path);
    }
  }

}
