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
   * Returns all declared defaults keyed by entity type and bundle.
   *
   * The node's own defaults sit under its content type. When several items
   * share a bundle, the first declaration of a field wins.
   *
   * @return array
   *   Default definitions, as stored, keyed by entity type ID, then bundle,
   *   then field name. Bundles without defaults are absent.
   */
  public function getDefaultsByBundle(): array;

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
