<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool\Research;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Exception\DocumentPagerException;
use Drupal\oe_ai_assistant\Service\Subagent\DocumentPager;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

/**
 * Reads one page of a document of the session.
 */
#[NeuronTool(
  id: 'research_read_document',
  description: 'Reads one page of a document. Both document_id and page are required;'
  . ' pages start at 1 and document_id comes from research_list_documents or'
  . ' research_search_documents. Cite only pages you have read.',
  label: new TranslatableMarkup('Read document'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class ReadDocumentNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DocumentPager $pager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      ToolProperty::make('document_id', PropertyType::INTEGER, 'The id of the document to read.', TRUE),
      ToolProperty::make('page', PropertyType::INTEGER, 'The page number to read, starting at 1.', TRUE),
    ];
  }

  /**
   * Returns the page as JSON, or the reason it cannot be read.
   */
  public function __invoke(int $document_id, int $page): string|ToolOutput {
    try {
      return json_encode($this->pager->read($this->getContextValue('session'), $document_id, $page), JSON_THROW_ON_ERROR);
    }
    catch (DocumentPagerException $e) {
      return ToolOutput::error($e->getMessage());
    }
  }

}
