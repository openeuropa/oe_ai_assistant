<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;

/**
 * Returns the tone selected for this session, and the tones on offer.
 */
#[NeuronTool(
  id: 'context_get_tone_options',
  description: 'Returns the tone selected for this session, and every tone the editor can pick'
  . ' from, each with its label and description. Never includes a tone\'s AI guidelines.',
  label: new TranslatableMarkup('Get tone options'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetToneOptionsNeuronTool extends NeuronToolPluginBase {

  use SelectionDescriberTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AiEditorialContextInterface $editorialContext,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Returns the selected tone and the available options, as JSON.
   */
  public function __invoke(): string {
    $options = $this->editorialContext->getToneOptions();
    $selected = $this->describeSelection($this->getContextValue('session')->getTone(), $options);

    return (string) json_encode(['selected' => $selected, 'options' => $options], JSON_INVALID_UTF8_SUBSTITUTE);
  }

}
