<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Providers\AIProviderInterface;

/**
 * The agent that summarises an attached document for the editor.
 *
 * It takes the document and its session as context, so the summary's cost
 * and history are attributed to the session. The agent holds no
 * conversation with the editor and publishes no tools.
 */
#[NeuronAgent(
  id: SummaryNeuronAgent::PLUGIN_ID,
  label: new TranslatableMarkup('Document summary'),
  description: new TranslatableMarkup('Writes a short summary of an extracted document.'),
  operation_type: 'chat',
  enable_persistence: FALSE,
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
    'media' => new EntityContextDefinition(
      data_type: 'entity:media',
      label: new TranslatableMarkup('Document'),
    ),
  ],
)]
final class SummaryNeuronAgent extends NeuronAgentPluginBase {

  public const string PLUGIN_ID = 'document_summary';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    NeuronToolManagerInterface $toolManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $providers, $toolManager);
  }

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    return 'You summarise briefing documents for editors. '
      . 'Write a brief summary of the document in English, three to five sentences: '
      . 'what it is and its key points. Return only the summary.';
  }

  /**
   * {@inheritdoc}
   *
   * Keep the 'oe_ai_assistant' and 'document_summary' tags alongside the
   * one the base class derives from the plugin ID. A site's moderation or
   * prompt-logging config may be keyed on either of them, and it should go
   * on screening the one text here an editor fully controls: the document
   * they uploaded.
   */
  protected function provider(): AIProviderInterface {
    return $this->providers->create($this->operationType(), [
      'oe_ai_assistant',
      self::PLUGIN_ID,
      $this->tag(),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * We number the attempts so every run gets a thread of its own, and their
   * costs stay apart on the session's history page. A run re-sends the whole
   * thread it writes to, so one thread per document would send the previous
   * attempt's extract again and charge the session for the same text twice.
   */
  protected function threadKey(): string {
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->getContextValue('media');
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = $this->getContextValue('session');
    $key = sprintf('session_%s.media_%s', $session->id(), $media->id());
    $prefix = $this->runId($key) . '.attempt_';

    // Group by thread_id instead of loading the rows: each row holds the
    // whole message it stored, capped extract and all, and the thread names
    // are all we need.
    $threads = $this->entityTypeManager->getStorage('neuron_message')
      ->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('thread_id', $prefix, 'STARTS_WITH')
      ->groupBy('thread_id')
      ->execute();

    $attempt = 1;
    foreach ($threads as $thread) {
      $attempt = max($attempt, 1 + (int) substr((string) $thread['thread_id'], strlen($prefix)));
    }

    return $key . '.attempt_' . $attempt;
  }

}
