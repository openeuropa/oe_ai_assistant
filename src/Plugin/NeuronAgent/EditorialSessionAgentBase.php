<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentDefinition;
use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\ClosedToolInputAdapter;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;

/**
 * Base class for the agents that serve one editorial session.
 *
 * The session is the only thing a caller supplies, and an agent reads what it
 * drafts with off that. A subclass declares the session context on its own
 * attribute, because an attribute is not inherited.
 *
 * The session is also what names an agent's conversation, so a thread id is
 * composed here. Every agent prompts a model with what the editor set up, so
 * the blocks they inject are rendered here as well.
 */
abstract class EditorialSessionAgentBase extends NeuronAgentPluginBase {

  /**
   * Characters of one context document extract injected into a prompt.
   */
  public const int MAX_DOCUMENT_CHARS = 20000;

  /**
   * Characters of extracted text injected over all context documents.
   *
   * A context document whose text does not fit in the remaining budget
   * contributes its summary instead.
   */
  public const int MAX_TOTAL_CHARS = 60000;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    NeuronToolManagerInterface $toolManager,
    protected readonly DraftingBriefInterface $brief,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $providers, $toolManager);
  }

  /**
   * {@inheritdoc}
   *
   * The attribute names the tools, and every one of them serves this agent's
   * session: it reads what it needs off that rather than being handed it, so
   * they are built on the session context instead of on nothing.
   */
  protected function tools(): array {
    $definition = $this->getPluginDefinition();
    assert($definition instanceof NeuronAgentDefinition);
    $context = ['session' => $this->session()];

    return array_map(
      fn (string $id): ToolInterface => $this->toolManager->createTool($id, $context),
      $definition->tools,
    );
  }

  /**
   * {@inheritdoc}
   *
   * The declared protocol is Vercel's, which is what the app reads, and
   * Neuron's adapter for it leaves out the event that closes a tool call's
   * input. The app holds a call back until its input is closed, so the stream
   * is shaped by the subclass that adds it.
   */
  protected function streamAdapter(string $threadId): ?StreamAdapterInterface {
    return new ClosedToolInputAdapter();
  }

  /**
   * {@inheritdoc}
   *
   * A tool answers the model in JSON, so a failure is reported the same way
   * rather than as the sentence the base class returns. The call names itself
   * in it, with what it was given, so a model that called several tools in one
   * turn is told which of them failed and on what.
   */
  protected function toolErrorHandler(): ?callable {
    return static fn (\Throwable $exception, ToolCall $call): string => json_encode([
      'error' => $exception->getMessage(),
      'tool' => $call->getName(),
      'description' => $call->getDescription(),
      'inputs' => $call->getInputs(),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * The session leads, because a reader of the store groups by it: every thread
   * of one session sorts together, the editor's conversation and the runs it
   * started. The agent follows, and then whatever tells one of its runs from
   * another. Each segment says what it names, so a drafter run reads
   * "session_12.agent_field_group.run_a1b2c3d4.group_main_fields".
   */
  protected function runId(string $key): string {
    $thread = EditorialMessageStore::SESSION_PREFIX . $this->session()->id()
      . '.' . EditorialMessageStore::AGENT_PREFIX . $this->getPluginId();

    return $key === '' ? $thread : $thread . '.' . $key;
  }

  /**
   * {@inheritdoc}
   *
   * The session and the agent are the whole of the thread, so a turn picks up
   * the conversation the last one left. An agent that answers one question per
   * run names what tells its runs apart instead.
   */
  protected function threadKey(): string {
    return '';
  }

  /**
   * The session this agent serves.
   *
   * The context is required, so the manager refused the build without it.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session the caller named when it asked for the agent.
   */
  protected function session(): AiEditorialSessionInterface {
    $session = $this->getContextValue('session');
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * Renders the tone guidelines the drafters follow.
   *
   * @return string
   *   The prompt block, or an empty string without a tone.
   */
  protected function tonePrompt(): string {
    $tone = $this->brief->tone($this->session());
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

  /**
   * Builds the context documents block of the prompts.
   *
   * Every attached context document appears under its title and file name,
   * so the agents can tell which document the editor refers to: the
   * extracted text when the pipeline produced one, otherwise a note that
   * the content is not available. The router and the sub-agents share this
   * block, so the assistant can ask the editor to wait for pending material
   * or to retry failed material. Text is capped per document and over all
   * documents; a document whose text does not fit in the remaining budget
   * contributes its summary instead, and counts as not available while it
   * has none.
   *
   * @return string
   *   The prompt block, or an empty string without context documents.
   */
  protected function documentsPrompt(): string {
    $documents = $this->brief->documents($this->session());
    if ($documents === []) {
      return '';
    }

    $lines = ['Context documents attached by the editor as background for this draft:'];
    $budget = self::MAX_TOTAL_CHARS;
    $pending = FALSE;
    $failed = FALSE;
    foreach ($documents as $document) {
      $lines[] = '';
      $heading = '### ' . (string) ($document['title'] ?? $document['id'] ?? 'Document');
      $filename = trim((string) ($document['filename'] ?? ''));
      $lines[] = $filename === '' ? $heading : sprintf('%s (file: %s)', $heading, $filename);
      $extract = trim((string) ($document['extract'] ?? ''));
      $truncated = mb_strlen($extract) > self::MAX_DOCUMENT_CHARS;
      if ($truncated) {
        $extract = mb_substr($extract, 0, self::MAX_DOCUMENT_CHARS);
      }
      $summary = trim((string) ($document['summary'] ?? ''));
      if ($extract !== '' && mb_strlen($extract) <= $budget) {
        $budget -= mb_strlen($extract);
        $lines[] = $extract;
        // The marker is ours: it stays out of the budget, which only counts
        // document text.
        if ($truncated) {
          $lines[] = '[truncated]';
        }
      }
      elseif ($summary !== '') {
        $lines[] = 'Summary only: ' . $summary;
      }
      // The document has nothing to contribute: no text yet, or text that
      // does not fit and no summary to stand in for it. Waiting only helps a
      // document still in the pipeline; a failed one stays unavailable until
      // the editor retries it.
      elseif (($document['status'] ?? '') === DocumentExtractionProcessorInterface::STATE_ERROR) {
        $failed = TRUE;
        $lines[] = 'Processing failed; its content is not available.';
      }
      else {
        $pending = TRUE;
        $lines[] = 'Not processed yet; its content is not available.';
      }
    }

    $lines[] = '';
    $lines[] = 'Use the context documents as background only: never reproduce them verbatim and do not mention '
      . 'them unless the editor asks.';
    if ($pending) {
      $lines[] = 'Some documents are not available yet. Tell the editor to wait a moment for the full context, '
        . 'or warn that a draft produced now may miss part of the briefing material.';
    }
    if ($failed) {
      $lines[] = 'Some documents could not be processed. Tell the editor to retry them or to remove them, '
        . 'and warn that a draft produced now ignores their content.';
    }

    return implode("\n", $lines);
  }

}
