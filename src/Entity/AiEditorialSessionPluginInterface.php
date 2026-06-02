<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the interface for AI editorial session plugin instances.
 */
interface AiEditorialSessionPluginInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Returns the parent AI editorial session.
   */
  public function getSession(): AiEditorialSessionInterface;

  /**
   * Returns the plugin machine name.
   */
  public function getPluginId(): string;

  /**
   * Returns the plugin instance status.
   */
  public function getStatus(): string;

  /**
   * Sets the plugin instance status.
   */
  public function setStatus(string $status): self;

  /**
   * Returns stable plugin setup data.
   */
  public function getConfiguration(): array;

  /**
   * Sets stable plugin setup data.
   */
  public function setConfiguration(array $configuration): self;

  /**
   * Returns runtime plugin state.
   */
  public function getState(): array;

  /**
   * Sets runtime plugin state.
   */
  public function setState(array $state): self;

  /**
   * Returns one runtime state value.
   */
  public function getStateValue(string $key, mixed $default = NULL): mixed;

  /**
   * Sets one runtime state value.
   */
  public function setStateValue(string $key, mixed $value): self;

}
