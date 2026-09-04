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
   * Tests the entity_version snapshot.
   */
  public function testRecordSnapshotsVersion(): void {
    $this->container->get('entity_version.entity_version_installer')
      ->install('node', ['oe_news'], ['major' => 0, 'minor' => 1, 'patch' => 0]);
    $user = $this->createUser();
    $this->container->get('current_user')->setAccount($user);
    $session = $this->createSession($user);
    $draft = $this->createDraftTurn($session, ['input' => 1, 'output' => 2, 'total' => 3]);
    $node = Node::create(['type' => 'oe_news', 'title' => 'Versioned', 'uid' => $user->id()]);
    $node->save();

    $record = $this->container->get(ProvenanceRecorderInterface::class)->record($node, $session, $draft);

    $this->assertSame((int) $node->getRevisionId(), $record->getTrackedRevisionId());
    $this->assertSame(['major' => 0, 'minor' => 1, 'patch' => 0], $record->getVersion());
  }

}
