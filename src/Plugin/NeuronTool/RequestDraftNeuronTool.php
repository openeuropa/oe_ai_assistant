<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\DeferredToolPluginInterface;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Exceptions\ToolException;

/**
 * Asks the run for the next draft of the session.
 *
 * A tool runs between two yields of the agent that called it, so it cannot
 * stream the drafting of a group back to the editor. This call is deferred
 * instead: it suspends the agent, a node of the run writes the draft and
 * streams while it does, and the result reaches the model afterwards.
 */
#[NeuronTool(
  id: RequestDraftNeuronTool::TOOL_ID,
  description: 'Drafts the content of every field group and stores it as the next draft.'
  . ' Call it once, when the editor is ready for a draft. The result names the'
  . ' draft ("Draft 1", "Draft 2", and so on) and gives its version;'
  . ' drafting_get_history lists the drafts of this session.',
  label: new TranslatableMarkup('Draft content'),
)]
final class RequestDraftNeuronTool extends NeuronToolPluginBase implements DeferredToolPluginInterface {

  /**
   * The id this tool is registered under.
   */
  public const string TOOL_ID = 'drafting_draft_content';

  /**
   * {@inheritdoc}
   *
   * @throws \NeuronAI\Exceptions\ToolException
   *   Always. The run writes the draft, so a call here is a mistake.
   */
  public function __invoke(): string {
    throw new ToolException(sprintf(
      'The "%s" tool is drafted by the run: submit its result instead of executing it.',
      self::TOOL_ID,
    ));
  }

}
