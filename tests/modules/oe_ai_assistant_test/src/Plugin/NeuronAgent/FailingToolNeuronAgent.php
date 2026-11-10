<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_test\Plugin\NeuronAgent;

use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Plugin\NeuronAgent\EditorialSessionAgentBase;

/**
 * An editorial session agent whose only tool throws.
 *
 * Every tool the real agents declare has to succeed before a test gets near
 * toolErrorHandler() on EditorialSessionAgentBase. This agent declares one
 * tool that always throws, and nothing else.
 */
#[NeuronAgent(
  id: 'oe_ai_assistant_test_failing_tool',
  label: new TranslatableMarkup('Failing tool agent'),
  description: new TranslatableMarkup('Calls a tool that always throws.'),
  operation_type: 'chat_with_tools',
  tools: ['oe_ai_assistant_test_failing'],
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class FailingToolNeuronAgent extends EditorialSessionAgentBase {}
