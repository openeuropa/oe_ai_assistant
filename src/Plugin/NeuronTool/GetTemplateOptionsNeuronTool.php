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
  description: 'Returns the template selected for this session, and every template available'
  . ' for its content type, each with its label and description.',
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
    $options = $this->schemaProvider->availableTemplates($session->getContentType());
    $selected = $this->describeSelection($session->getTemplate(), $options);

    return (string) json_encode(['selected' => $selected, 'options' => $options], JSON_INVALID_UTF8_SUBSTITUTE);
  }

  /**
   * Describes what the session holds as one of the options.
   *
   * A session stores only the ID and the label of what it holds, so we read
   * the description from the options. An option the site has since disabled
   * is off the list, so the fallback reports the ID and the label with no
   * description.
   *
   * @param array|null $selection
   *   What the session holds, NULL when it holds nothing.
   * @param array $options
   *   The options to read the description from.
   *
   * @return array|null
   *   The selection with its 'id', 'label' and 'description', or NULL when
   *   the session holds nothing.
   */
  private function describeSelection(?array $selection, array $options): ?array {
    if ($selection === NULL) {
      return NULL;
    }

    $id = (string) ($selection['id'] ?? '');
    foreach ($options as $option) {
      if ($option['id'] === $id) {
        return $option;
      }
    }

    return [
      'id' => $id,
      'label' => $selection['label'] ?? '',
      'description' => '',
    ];
  }

}
