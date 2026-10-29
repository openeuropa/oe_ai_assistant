<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Traits;

use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Trait that sets up text format permissions and field restrictions.
 */
trait TextFormatUserTrait {

  use UserCreationTrait;

  /**
   * Makes the current user one who may use exactly the given formats.
   *
   * @param string[] $formats
   *   The text format IDs the user may use.
   * @param string[] $permissions
   *   Further permissions the user has.
   */
  protected function setUserWithFormats(array $formats, array $permissions = []): void {
    $this->setUpCurrentUser([], [
      ...$permissions,
      ...array_map(static fn (string $format): string => "use text format $format", $formats),
    ]);
  }

  /**
   * Restricts a field to the given formats.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $fieldName
   *   The field name.
   * @param string[] $formats
   *   The format IDs.
   */
  protected function restrictFormats(string $entityTypeId, string $bundle, string $fieldName, array $formats): void {
    $fieldConfig = FieldConfig::loadByName($entityTypeId, $bundle, $fieldName);
    $fieldConfig->setSetting('allowed_formats', $formats);
    $fieldConfig->save();
  }

}
