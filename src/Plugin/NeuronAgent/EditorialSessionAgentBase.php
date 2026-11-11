<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentDefinition;
use Drupal\Component\Plugin\Definition\ContextAwarePluginDefinitionInterface;
use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;

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

  use LoggerChannelTrait;

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

  /**
   * Caps a document extract at the per-document character budget.
   *
   * @param string $extract
   *   The extract to cap.
   *
   * @return array{0: string, 1: bool}
   *   The extract, capped if needed, and whether it was capped.
   */
  public static function truncateDocumentExtract(string $extract): array {
    if (mb_strlen($extract) <= self::MAX_DOCUMENT_CHARS) {
      return [$extract, FALSE];
    }

    return [mb_substr($extract, 0, self::MAX_DOCUMENT_CHARS), TRUE];
  }

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
   * Our tools return JSON to the model, so a failure is reported as JSON too,
   * instead of the plain sentence NeuronAgentPluginBase builds. The payload
   * names the tool, so a model that called several tools in one turn can see
   * which one failed.
   */
  protected function toolErrorHandler(): ?callable {
    $logger = $this->getLogger('oe_ai_assistant');

    return static function (\Throwable $exception, ToolCall $call) use ($logger): string {
      // Don't return the exception message to the model: a database driver
      // puts the failing query and the host in it, and the agent repeats
      // whatever we return to the editor. It goes to the log instead.
      $logger->error('The @tool tool failed: @message', [
        '@tool' => $call->getName(),
        '@message' => $exception->getMessage(),
        'exception' => $exception,
      ]);

      // json_encode() returns string|false and the handler has to return a
      // string. It can only fail on data it can't encode, and all we encode
      // is a tool name and a fixed sentence, so the cast is safe.
      return (string) json_encode([
        'tool' => $call->getName(),
        'error' => 'The tool failed.',
      ]);
    };
  }

  /**
   * {@inheritdoc}
   *
   * The session is the whole key, so the next turn carries on the
   * conversation the last one left off. Keep it in step with
   * AiEditorialSession::editorialThread(), which spells out the same thread
   * id by hand, because the transcript endpoint reads the stored rows with
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
    $documents = $this->getContextValue('session')->getDocuments();
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
      [$extract, $truncated] = self::truncateDocumentExtract($extract);
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
