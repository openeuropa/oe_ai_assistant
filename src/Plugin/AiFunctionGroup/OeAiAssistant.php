<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionGroup;

use Drupal\ai\Attribute\FunctionGroup;
use Drupal\ai\Service\FunctionCalling\FunctionGroupInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * OpenEuropa AI Assistant function group.
 */
#[FunctionGroup(
  id: 'oe_ai_assistant',
  group_name: new TranslatableMarkup('OpenEuropa AI Assistant'),
  description: new TranslatableMarkup('Lookup and drafting helper tools exposed by OpenEuropa AI Assistant.'),
)]
final class OeAiAssistant implements FunctionGroupInterface {

}
