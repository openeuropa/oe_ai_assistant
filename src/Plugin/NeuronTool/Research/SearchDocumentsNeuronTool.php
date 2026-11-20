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
 * Searches the documents of the session for the words of a query.
 */
#[NeuronTool(
  id: 'research_search_documents',
  description: 'Searches all documents for the words of a query and returns the best'
  . ' pages, each with document_id, page and a snippet. Use a few specific words'
  . ' of 3 or more characters. Read a page before you cite it.',
  label: new TranslatableMarkup('Search documents'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class SearchDocumentsNeuronTool extends NeuronToolPluginBase {

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
      ToolProperty::make('query', PropertyType::STRING, 'The words to search for, separated by spaces.', TRUE),
    ];
  }

  /**
   * Returns the matching pages as JSON, or the reason the query is refused.
   */
  public function __invoke(string $query): string|ToolOutput {
    try {
      return json_encode(['results' => $this->pager->search($this->getContextValue('session'), $query)], JSON_THROW_ON_ERROR);
    }
    catch (DocumentPagerException $e) {
      return ToolOutput::error($e->getMessage());
    }
  }

}
