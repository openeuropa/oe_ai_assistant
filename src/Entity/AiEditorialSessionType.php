<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Defines the AI editorial session bundle entity.
 *
 * @ConfigEntityType(
 *   id = "ai_editorial_session_type",
 *   label = @Translation("AI editorial session type"),
 *   label_collection = @Translation("AI editorial session types"),
 *   bundle_of = "ai_editorial_session",
 *   config_prefix = "ai_editorial_session_type",
 *   admin_permission = "administer ai editorial sessions",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *   }
 * )
 */
class AiEditorialSessionType extends ConfigEntityBundleBase {

}
