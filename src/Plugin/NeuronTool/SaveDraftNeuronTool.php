<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Saves a stored draft of the session once the editor has confirmed.
 *
 * The call is held for the editor's decision before it reaches __invoke(),
 * so nothing is written until the editor agrees. A call that names no draft
 * is not held: there is nothing to confirm, and it answers an error.
 */
#[NeuronTool(
  id: SaveDraftNeuronTool::TOOL_ID,
  description: 'Saves a stored draft of this session as an unpublished revision of the content'
  . ' item. The editor confirms in a dialog before anything is written. Call it with the'
  . ' version of the draft ("Draft 2" is version 2). Report what the result says: a nodeId'
  . ' means saved, an error means not saved.',
  label: new TranslatableMarkup('Save draft'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class SaveDraftNeuronTool extends NeuronToolPluginBase {

  /**
   * The id this tool is registered under.
   */
  public const string TOOL_ID = 'drafting_save_draft';

  /**
   * What the model reads when the editor cancels a save.
   *
   * It has no closing period, because Neuron's rejection wrapper adds one.
   */
  public const string CANCELLED = 'The editor cancelled this save, so nothing was written. A later request to save is a new request: call ' . self::TOOL_ID . ' again';

  /**
   * The saver that writes the node.
   */
  private DraftSaverInterface $draftSaver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->draftSaver = $container->get(DraftSaverInterface::class);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getNeuron(): Tool {
    $tool = parent::getNeuron();
    // Neuron asks the policy again on the pass that applies the decision, so
    // it answers the same way on every pass and never throws.
    $tool->withApprovalPolicy(fn (ToolInterface $tool): bool|string => $this->draftWithFields($tool->getInputs()['version'] ?? NULL) === NULL
      ? FALSE
      : 'The editor confirms before a draft is saved.');

    return $tool;
  }

  /**
   * Saves the draft of the given version and returns the outcome as JSON.
   */
  public function __invoke(int $version): string {
    // A draft gone since the request lets the call run without a decision.
    $draft = $this->draftWithFields($version);
    if ($draft === NULL) {
      return json_encode([
        'version' => $version,
        'error' => sprintf('Draft %d does not exist in this session.', $version),
      ]);
    }

    try {
      $result = $this->draftSaver->save($this->getContextValue('session'), $draft['fields'], $draft['templateId'], $version);
    }
    catch (ActionException $e) {
      return json_encode(['version' => $version, 'error' => $e->getMessage()]);
    }

    return json_encode(['version' => $version] + $result);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty('version', PropertyType::INTEGER, 'The version of the draft to save.', TRUE),
    ];
  }

  /**
   * Reads the stored draft of a version, when it has fields to save.
   *
   * @param mixed $version
   *   The version the call names.
   *
   * @return array|null
   *   The draft's fields and template id, or NULL when there is none.
   */
  private function draftWithFields(mixed $version): ?array {
    $draft = $this->getContextValue('session')->getDraftContent((int) $version);

    return $draft === NULL || $draft['fields'] === [] ? NULL : $draft;
  }

}
