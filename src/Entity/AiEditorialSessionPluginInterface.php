<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the interface for AI editorial session plugin instances.
 */
interface AiEditorialSessionPluginInterface extends ContentEntityInterface, EntityChangedInterface {

}
