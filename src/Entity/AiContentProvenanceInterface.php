<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the AI content provenance entity interface.
 */
interface AiContentProvenanceInterface extends ContentEntityInterface, EntityOwnerInterface {}
