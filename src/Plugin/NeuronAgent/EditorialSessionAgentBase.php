<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;

/**
 * Base class for the agents that serve one editorial session.
 *
 * The session is the only thing a caller supplies, and an agent reads what it
 * drafts with off that. A subclass declares the session context on its own
 * attribute, because an attribute is not inherited.
 *
 * The session is also what names an agent's conversation, so the thread key is
 * decided here. Every agent prompts a model with what the editor set up, so the
 * blocks they inject are rendered here as well. The renderers take the pieces
 * the brief answers with and read nothing themselves, so a prompt rule is
 * checked on its own.
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

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\ai_neuron\Providers\ProviderFactoryInterface $providers
   *   Resolves the provider the site configured for this agent.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface $brief
   *   Reads what the session drafts with.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    protected readonly DraftingBriefInterface $brief,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $providers);
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
   * @param array $tone
   *   The tone, as tone() answers it, or NULL when none is selected.
   *
   * @return string
   *   The prompt block, or an empty string without a tone.
   */
  public static function tonePrompt(?array $tone): string {
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
   * @param array $documents
   *   The descriptors, as documents() answers them.
   *
   * @return string
   *   The prompt block, or an empty string without context documents.
   */
  public static function documentsPrompt(array $documents): string {
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

  /**
   * {@inheritdoc}
   *
   * The editorial session is the conversation, so a turn picks up where the
   * last one left off. An agent that answers one question per run names its
   * own thread instead.
   */
  protected function threadKey(): string {
    return ThreadAddress::key((string) $this->session()->id());
  }

}
