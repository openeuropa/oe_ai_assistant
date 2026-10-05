<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;

/**
 * Lists what the editor changed in the session, in the order it happened.
 *
 * The session records a row for every editorial change, and this reads them
 * back. They stay out of the conversation the model is given, so a change
 * costs nothing on a turn that does not ask about one, and an answer here
 * covers the whole session rather than the part that still fits a prompt.
 */
#[NeuronTool(
  id: 'get_session_history',
  description: 'Returns what the editor changed in this session and when:'
  . ' the tone and template it started with, every later change to either,'
  . ' and every draft saved. Call it to answer what changed, when, how'
  . ' often, or what a setting was before.',
  label: new TranslatableMarkup('Get session history'),
)]
final class GetSessionHistoryNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DraftingTurn $turn,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Returns the editorial changes of the session as JSON.
   */
  public function __invoke(): string {
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    assert($storage instanceof AiConversationMessageStorageInterface);

    $changes = [];
    foreach ($storage->loadTranscript($this->turn->session()) as $row) {
      if ($row->getRole() !== AiConversationMessageInterface::ROLE_EVENT) {
        continue;
      }
      $metadata = $row->getMetadata();
      $change = [
        'type' => (string) ($metadata['type'] ?? ''),
        'summary' => (string) $row->get('content')->value,
        'at' => (string) $row->get('created')->date?->format('c'),
      ];
      // A change says what it moved away from and to, so the model can
      // answer about an earlier setting and not only the current one.
      foreach (['from', 'to'] as $side) {
        if (isset($metadata[$side])) {
          $change[$side] = $metadata[$side];
        }
      }
      $changes[] = $change;
    }

    return json_encode(['changes' => $changes]);
  }

}
