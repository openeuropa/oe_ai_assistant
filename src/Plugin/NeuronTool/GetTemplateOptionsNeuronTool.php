<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;

/**
 * Returns the template selected for this session, and the templates on offer.
 */
#[NeuronTool(
  id: 'context_get_template_options',
  description: 'Returns the ID of the template selected for this session, and every template'
  . ' available for its content type, each with its ID, label and description. Match the'
  . ' selected ID against the options to name it.',
  label: new TranslatableMarkup('Get template options'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetTemplateOptionsNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DraftingSchemaProviderInterface $schemaProvider,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Returns the selected template and the available options, as JSON.
   */
  public function __invoke(): string {
    $session = $this->getContextValue('session');
    $template = $session->getTemplate();

    return (string) json_encode([
      'selected' => $template === NULL ? NULL : $template['id'],
      'options' => $this->schemaProvider->availableTemplates($session->getContentType()),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
  }

}
