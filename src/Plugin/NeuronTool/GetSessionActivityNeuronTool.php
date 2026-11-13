<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;

/**
 * Reports what happened in this session, and when.
 *
 * The agent binds the session as tool context, and the tool takes no
 * arguments. The model cannot ask for another session's activity.
 */
#[NeuronTool(
  id: 'context_get_session_activity',
  description: 'Returns what happened in this session, oldest first: each tone'
  . ' and template the editor picked, each document attached or removed,'
  . ' each draft generated or failed, and each draft saved to the CMS.'
  . ' Every entry says when it happened and who did it. Call it to answer'
  . ' what the editor changed, when, or in which order.',
  label: new TranslatableMarkup('Get session activity'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetSessionActivityNeuronTool extends NeuronToolPluginBase {

  /**
   * How many entries one call reports.
   *
   * Neuron stores a tool result as a thread row, and re-sends that row on
   * every later turn. Without a cap, a long session would pay for its
   * whole history on every later request.
   */
  public const int MAX_ENTRIES = 100;

  /**
   * Returns the activity of the session as JSON.
   */
  public function __invoke(): string {
    $session = $this->getContextValue('session');
    $entries = $session->getLatestActivity(self::MAX_ENTRIES);

    return json_encode([
      'activity' => array_map(
        static fn (AiEditorialSessionActivityInterface $entry): array => [
          'at' => date('c', $entry->getCreatedTime()),
          'by' => (string) ($entry->getOwner()?->getDisplayName() ?? ''),
          'message' => $entry->getMessage(),
        ],
        $entries,
      ),
      // We send only the last MAX_ENTRIES entries of a longer session. The
      // model would then read the oldest entry it got as the first thing
      // that happened. So we also report how many we left out.
      'earlier_entries_omitted' => max(0, $session->countActivity() - count($entries)),
    ]);
  }

}
