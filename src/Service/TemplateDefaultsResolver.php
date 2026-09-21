<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves the default values a drafting template declares for a bundle.
 */
class TemplateDefaultsResolver implements TemplateDefaultsResolverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(array $defaults, string $entityTypeId, string $bundle): array {
    $defaults = $this->resolveTokens($defaults, $this->time->getRequestTime());
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);

    foreach ($defaults as $fieldName => &$default) {
      if (!isset($fieldDefinitions[$fieldName]) || !is_array($default['default_value'] ?? NULL)) {
        continue;
      }
      $default['default_value'] = $this->resolveFieldDefaultValue(
        $default['default_value'],
        $fieldDefinitions[$fieldName],
        $entityTypeId,
        $bundle,
      );
    }

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFieldDefaultValue(array $defaultValue, FieldDefinitionInterface $fieldDefinition, string $entityTypeId, string $bundle): array {
    if (!$this->containsTargetUuid($defaultValue)) {
      return $defaultValue;
    }

    // Core resolves target_uuid the same way for field config defaults and
    // silently drops an entry it cannot resolve, hence the count check.
    $bundleKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKey('bundle');
    $entity = $this->entityTypeManager->getStorage($entityTypeId)
      ->create($bundleKey ? [$bundleKey => $bundle] : []);
    $itemListClass = $fieldDefinition->getClass();
    $resolved = $itemListClass::processDefaultValue($defaultValue, $entity, $fieldDefinition);

    if (count($resolved) < count($defaultValue)) {
      // The exception reaches the caller without the field it came from, so
      // name the reference here while it is still known.
      $this->logger->warning('Default of field "@field" on @type:@bundle references a uuid that no entity carries: @uuids', [
        '@field' => $fieldDefinition->getName(),
        '@type' => $entityTypeId,
        '@bundle' => $bundle,
        '@uuids' => implode(', ', $this->collectTargetUuids($defaultValue)),
      ]);
      throw new \RuntimeException(sprintf(
        "One or more target_uuid values for field '%s' do not reference an existing entity.",
        $fieldDefinition->getName(),
      ));
    }

    return $resolved;
  }

  /**
   * Lists the uuids a default value list names as reference targets.
   *
   * @param array $defaultValue
   *   The default value list.
   *
   * @return array
   *   The target uuids, in the order they appear.
   */
  private function collectTargetUuids(array $defaultValue): array {
    $uuids = [];
    foreach ($defaultValue as $item) {
      if (is_array($item) && isset($item['target_uuid'])) {
        $uuids[] = (string) $item['target_uuid'];
      }
    }
    return $uuids;
  }

  /**
   * Tells whether any item of a default value list names its target by uuid.
   */
  private function containsTargetUuid(array $defaultValue): bool {
    foreach ($defaultValue as $item) {
      if (is_array($item) && array_key_exists('target_uuid', $item)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Recursively replaces the __NOW__ token with the given timestamp.
   */
  private function resolveTokens(mixed $value, int $now): mixed {
    if ($value === '__NOW__') {
      return $now;
    }
    if (!is_array($value)) {
      return $value;
    }
    return array_map(
      fn(mixed $item): mixed => $this->resolveTokens($item, $now),
      $value,
    );
  }

}
