<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool\Research;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Subagent\DocumentPager;

/**
 * Lists the documents of the session that the researcher can read.
 *
 * The session comes from the run, so the model cannot reach another session.
 */
#[NeuronTool(
  id: 'research_list_documents',
  description: 'Lists the documents you can research, each with document_id, title,'
  . ' summary, characters and pages. Takes no input.',
  label: new TranslatableMarkup('List documents'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class ListDocumentsNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DocumentPager $pager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Returns the documents of the session as JSON.
   */
  public function __invoke(): string {
    return json_encode(['documents' => $this->pager->listDocuments($this->getContextValue('session'))], JSON_THROW_ON_ERROR);
  }

}
