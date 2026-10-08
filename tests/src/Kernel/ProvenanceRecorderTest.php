<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Service\ProvenanceRecorderInterface;
use Drupal\Tests\oe_ai_assistant\Traits\AiConversationMessageTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the provenance recorder.
 */
#[Group('oe_ai_assistant')]
class ProvenanceRecorderTest extends AiEditorialSessionKernelTestBase {

  use AiConversationMessageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_version'];

  /**
   * Tests that pending provenance is finalized on the same record.
   */
  public function testPendingProvenanceIsFinalizedOnSave(): void {
    $this->container->get('entity_version.entity_version_installer')
      ->install('node', ['oe_news'], ['major' => 0, 'minor' => 1, 'patch' => 0]);
    $user = $this->createUser();
    $this->container->get('current_user')->setAccount($user);
    $session = $this->createSession($user);
    $initialTurn = $this->createMessage($session, 'assistant', 'Initial discussion.');
    $initialTurn->setTokenUsage(['input' => 100, 'output' => 200, 'total' => 300]);
    $initialTurn->save();
    $previousDraft = $this->createDraftTurn($session, ['input' => 30, 'output' => 40, 'total' => 70]);
    $previousChild = $this->createMessage($session, 'assistant', 'Previous sub-agent result.', (int) $previousDraft->id());
    $previousChild->setTokenUsage(['input' => 50, 'output' => 60, 'total' => 110]);
    $previousChild->save();
    $earlierTurn = $this->createMessage($session, 'assistant', 'Tell me more.');
    $earlierTurn->setTokenUsage(['input' => 10, 'output' => 20]);
    $earlierTurn->save();
    $draft = $this->createDraftTurn($session, ['input' => 1, 'output' => 2, 'total' => 3]);
    $child = $this->createMessage($session, 'assistant', 'Sub-agent result.', (int) $draft->id());
    $child->setTokenUsage(['input' => 4, 'output' => 5, 'total' => 9]);
    $child->save();
    $laterTurn = $this->createMessage($session, 'assistant', 'A later turn.');
    $laterTurn->setTokenUsage(['input' => 100, 'output' => 200, 'total' => 300]);
    $laterTurn->save();

    $recorder = $this->container->get(ProvenanceRecorderInterface::class);
    $pending = $recorder->recordDraft($session, $draft);

    $this->assertNotNull($pending);
    $this->assertNull($pending->getTrackedEntityTypeId());
    $this->assertNull($pending->getTrackedEntityId());
    $this->assertNull($pending->getTrackedRevisionId());
    $this->assertCount(0, $pending->validate());
    $this->assertSame('Unsaved draft from message ' . $draft->id(), $pending->label());
    $this->assertSame(['input' => 15, 'output' => 27, 'total' => 42], $pending->getTokenUsage());

    $refreshed = $recorder->recordDraft($session, $draft);
    $this->assertSame((int) $pending->id(), (int) $refreshed?->id());

    $node = Node::create(['type' => 'oe_news', 'title' => 'Versioned', 'uid' => $user->id()]);
    $node->save();

    $record = $recorder->record($node, $session, $draft);

    $this->assertNotNull($record);
    $this->assertSame((int) $pending->id(), (int) $record->id());
    $this->assertSame('node', $record->getTrackedEntityTypeId());
    $this->assertSame((int) $node->id(), $record->getTrackedEntityId());
    $this->assertSame((int) $node->getRevisionId(), $record->getTrackedRevisionId());
    $this->assertSame(['major' => 0, 'minor' => 1, 'patch' => 0], $record->getVersion());
    $this->assertCount(1, $this->container->get('entity_type.manager')
      ->getStorage('ai_content_provenance')
      ->loadByProperties(['message' => $draft->id()]));
  }

  /**
   * Tests that saving a legacy draft creates finalized provenance directly.
   */
  public function testRecordCreatesFinalizedProvenanceWithoutPendingRecord(): void {
    $user = $this->createUser();
    $this->container->get('current_user')->setAccount($user);
    $session = $this->createSession($user);
    $draft = $this->createDraftTurn($session);
    $node = Node::create(['type' => 'oe_news', 'title' => 'Legacy', 'uid' => $user->id()]);
    $node->save();

    $record = $this->container->get(ProvenanceRecorderInterface::class)
      ->record($node, $session, $draft);

    $this->assertNotNull($record);
    $this->assertSame((int) $node->getRevisionId(), $record->getTrackedRevisionId());
  }

}
