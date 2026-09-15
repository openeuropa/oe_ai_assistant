<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides an interface defining an AI drafting template config entity.
 */
interface AiDraftingTemplateInterface extends ConfigEntityInterface {

  /**
   * Validates the template against Drupal field definitions.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   The template constraint violations.
   */
  public function validate(): ConstraintViolationListInterface;

  /**
   * Returns the defaults map with special tokens resolved.
   *
   * Supported tokens inside default value structures: __NOW__ → current Unix
   * timestamp.
   *
   * @return array<string, mixed>
   *   The mapping with tokens resolved.
   */
  public function resolveDefaults(): array;

  /**
   * Resolves an item's own defaults map, for an arbitrary entity type/bundle.
   *
   * Same token (__NOW__) and target_uuid -> target_id resolution as
   * resolveDefaults(), parameterized for a reference/paragraph item's own
   * entity type and bundle rather than this template's node content type.
   *
   * @param array<string, mixed> $rawDefaults
   *   The item's raw defaults map (the item's 'defaults' key, or []).
   * @param string $entityTypeId
   *   The item's entity type ID (e.g. 'paragraph', 'node').
   * @param string $bundle
   *   The item's bundle.
   *
   * @return array<string, mixed>
   *   The mapping with tokens and target_uuid resolved.
   */
  public function resolveItemDefaults(array $rawDefaults, string $entityTypeId, string $bundle): array;

  /**
   * Returns the human-readable description.
   */
  public function getDescription(): string;

  /**
   * Returns the target node bundle machine name.
   */
  public function getContentType(): string;

  /**
   * Returns the ordered field definitions map.
   *
   * @return array<string, mixed>
   *   The ordered fields.
   */
  public function getFields(): array;

  /**
   * Returns the default values map.
   *
   * @return array<string, mixed>
   *   The defaults mapping.
   */
  public function getDefaults(): array;

}
