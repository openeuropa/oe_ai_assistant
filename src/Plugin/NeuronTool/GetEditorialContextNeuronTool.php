<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;

/**
 * Describes what the editor set up for this session and what they can pick.
 *
 * We report the tone and the template with the options the editor can pick
 * from, so the agent can answer "which tones can I choose from?". Documents
 * are listed by title and summary, not by their text, to keep the answer
 * short. context_read_document returns the full text of one document.
 */
#[NeuronTool(
  id: 'context_get_editorial_context',
  description: 'Returns what the editor set up for this session and what else'
  . ' they can pick: the tone and the template, each with the options'
  . ' available, and the documents attached as background, each with its'
  . ' title, processing state and summary. Call it to answer what the'
  . ' session is about, what material is attached or what it covers, and'
  . ' to answer which tones or templates the editor can choose from.',
  label: new TranslatableMarkup('Get editorial context'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetEditorialContextNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AiEditorialContextInterface $editorialContext,
    private readonly DraftingSchemaProviderInterface $schemaProvider,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Returns the tone, the template, their options and the documents as JSON.
   */
  public function __invoke(): string {
    $session = $this->getContextValue('session');
    // An option names and describes a tone and leaves out its AI
    // guidelines. The result of this call is saved as a thread row, so
    // after the editor switches from Formal to Plain that row would still
    // tell the model to write formally. The guidelines reach the model
    // only through EditorialSessionAgentBase::tonePrompt(), which renders
    // them fresh for GroupDrafterNeuronAgent when it drafts a field.
    $tones = $this->editorialContext->getToneOptions();
    $templates = $this->schemaProvider->availableTemplates($session->getContentType());

    return json_encode([
      'tone' => [
        'selected' => $this->describeSelection($session->getTone(), $tones),
        'options' => $tones,
      ],
      'template' => [
        'selected' => $this->describeSelection($session->getTemplate(), $templates),
        'options' => $templates,
      ],
      'documents' => array_map(
        static fn (array $document): array => [
          'id' => $document['id'] ?? '',
          'title' => $document['title'] ?? '',
          'filename' => $document['filename'] ?? '',
          'status' => $document['status'] ?? '',
          'summary' => $document['summary'] ?? '',
        ],
        $session->getDocuments(),
      ),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
  }

  /**
   * Describes what the session holds as one of the options.
   *
   * A session knows the id and the label of what it holds, but not the
   * description, so we look the selection up in the options and report the
   * entry we find. An option the site disabled after the editor picked it
   * is no longer on the list, so we report the id and the label with an
   * empty description. We build that entry by hand rather than pass the
   * session's array on, because a tone's array also holds the guidelines
   * text.
   *
   * @param array|null $selection
   *   What the session holds, NULL when it holds nothing.
   * @param array $options
   *   The options to read the description from.
   *
   * @return array|null
   *   The selection as {id, label, description}, or NULL when the session
   *   holds nothing.
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
