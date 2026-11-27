<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentDefinition;
use Drupal\Component\Plugin\Definition\ContextAwarePluginDefinitionInterface;
use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\Core\Logger\LoggerChannelTrait;
use NeuronAI\Tools\ToolInterface;

/**
 * Base class for the agents that serve one editorial session.
 *
 * The session is the only thing a caller supplies, and an agent reads what it
 * drafts with off that. A subclass declares the session context on its own
 * attribute, because an attribute is not inherited.
 *
 * The session is also what names an agent's conversation, so a thread ID is
 * composed here. Every agent prompts a model with the tone the editor set
 * up, which is rendered here.
 */
abstract class EditorialSessionAgentBase extends NeuronAgentPluginBase {

  use JsonToolErrorHandlerTrait;
  use LoggerChannelTrait;

  /**
   * {@inheritdoc}
   *
   * The attribute lists the tools, and they all serve this agent's session.
   * A tool reads off the session whatever it needs, so the session is the
   * only context we have to pass down.
   */
  protected function tools(): array {
    $definition = $this->getPluginDefinition();
    assert($definition instanceof NeuronAgentDefinition);
    $context = ['session' => $this->getContextValue('session')];

    return array_map(
      fn (string $id): ToolInterface => $this->toolManager->createTool($id, $this->contextFor($id, $context)),
      $definition->tools,
    );
  }

  /**
   * The part of the run context that one tool declares.
   *
   * Core refuses a context a plugin does not declare, so each tool is given
   * what it asks for and nothing else.
   *
   * @param string $toolId
   *   The tool to build.
   * @param array $context
   *   The whole run context of this agent.
   *
   * @return array
   *   The entries the tool declares.
   */
  private function contextFor(string $toolId, array $context): array {
    $definition = $this->toolManager->getDefinition($toolId);
    assert($definition instanceof ContextAwarePluginDefinitionInterface);

    return array_intersect_key($context, $definition->getContextDefinitions());
  }

  /**
   * {@inheritdoc}
   *
   * The session is the whole key, so the next turn carries on the
   * conversation the last one left off. Keep it in step with
   * AiEditorialSession::editorialThread(), which spells out the same thread
   * ID by hand, because the transcript endpoint reads the stored rows with
   * no agent to ask. A subclass that answers one question per run, like
   * GroupDrafterNeuronAgent, appends what tells its own runs apart.
   */
  protected function threadKey(): string {
    return 'session_' . $this->getContextValue('session')->id();
  }

  /**
   * Renders the tone guidelines the drafters follow.
   *
   * @return string
   *   The prompt block, or an empty string without a tone.
   */
  protected function tonePrompt(): string {
    $tone = $this->getContextValue('session')->getTone();
    $prompt = trim((string) ($tone['prompt'] ?? ''));
    if ($prompt === '') {
      return '';
    }

    return implode("\n", [
      'Editorial context selected by the editor for this draft:',
      sprintf('- Tone: %s', (string) ($tone['label'] ?? '')),
      sprintf('- Tone guidelines: %s', $prompt),
      '',
      'Follow the tone guidelines in every piece of text you generate.',
    ]);
  }

}
