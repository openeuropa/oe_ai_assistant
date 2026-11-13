<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\ActivityType;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the activity record of an editorial session.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionActivityTest extends AiEditorialSessionKernelTestBase {

  /**
   * An entry records its session, its author, its message and its time.
   */
  public function testEntryRecordsWhatHappened(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $this->container->get('current_user')->setAccount($owner);

    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "%s".', 'Formal');

    $entries = $session->getLatestActivity(100);
    $this->assertCount(1, $entries);
    $this->assertSame($session->id(), $entries[0]->getSession()->id());
    $this->assertSame((int) $owner->id(), (int) $entries[0]->getOwnerId());
    $this->assertSame('Tone set to "Formal".', $entries[0]->getMessage());
    $this->assertSame(ActivityType::ToneChanged, $entries[0]->getType());
    $this->assertEqualsWithDelta(
      $this->container->get('datetime.time')->getCurrentTime(),
      $entries[0]->getCreatedTime(),
      5,
    );
  }

  /**
   * The entry names the user who acted, not the owner of the session.
   */
  public function testEntryRecordsTheActingUser(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $collaborator = $this->createUser();
    $this->container->get('current_user')->setAccount($collaborator);

    $session->recordActivity(ActivityType::DocumentAdded, 'Document "brief.txt" attached.');

    $entries = $session->getLatestActivity(100);
    $this->assertSame((int) $collaborator->id(), (int) $entries[0]->getOwnerId());
  }

  /**
   * Nothing edits an entry, not even a user who administers the sessions.
   */
  public function testEntryCannotBeUpdated(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $this->container->get('current_user')->setAccount($owner);
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');
    $entry = $session->getLatestActivity(100)[0];
    $administrator = $this->createUser(['administer ai editorial sessions']);

    $this->assertFalse($entry->access('update', $owner));
    $this->assertFalse($entry->access('update', $administrator));
    $this->assertTrue($entry->access('view', $administrator));
  }

  /**
   * The latest entries come back oldest first, and a limit keeps the newest.
   */
  public function testLatestReturnsTheNewestEntriesOldestFirst(): void {
    $session = $this->createSession($this->createUser());
    foreach (['one', 'two', 'three'] as $message) {
      $session->recordActivity(ActivityType::ToneChanged, $message);
    }

    $this->assertSame(
      ['one', 'two', 'three'],
      array_map(
        static fn (AiEditorialSessionActivityInterface $entry): string => $entry->getMessage(),
        $session->getLatestActivity(100),
      ),
    );
    $this->assertSame(
      ['two', 'three'],
      array_map(
        static fn (AiEditorialSessionActivityInterface $entry): string => $entry->getMessage(),
        $session->getLatestActivity(2),
      ),
    );
  }

  /**
   * A session reads its own entries and no other session's.
   */
  public function testSessionReadsOnlyItsOwnEntries(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $other = $this->createSession($owner);

    $session->recordActivity(ActivityType::ToneChanged, 'mine');
    $other->recordActivity(ActivityType::ToneChanged, 'theirs');

    $this->assertSame(
      ['mine'],
      array_map(
        static fn (AiEditorialSessionActivityInterface $entry): string => $entry->getMessage(),
        $session->getLatestActivity(100),
      ),
    );
    $this->assertSame(1, $session->countActivity());
    $this->assertSame(1, $other->countActivity());
  }

  /**
   * Deleting a session takes its entries with it, and leaves the others.
   */
  public function testDeletingSessionDeletesItsEntries(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $other = $this->createSession($owner);
    $session->recordActivity(ActivityType::ToneChanged, 'mine');
    $other->recordActivity(ActivityType::ToneChanged, 'theirs');

    $session->delete();

    $this->assertSame(0, $session->countActivity());
    $this->assertSame(1, $other->countActivity());
  }

  /**
   * An entry that cannot be written does not fail the action behind it.
   */
  public function testRecordDoesNotThrowWhenTheTableIsGone(): void {
    $session = $this->createSession($this->createUser());
    $this->container->get('database')->schema()->dropTable('ai_editorial_session_activity');

    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');

    $this->assertTrue(TRUE, 'Recording an entry reported no failure of its own.');
  }



















  /**
   * Reads back the sentences recorded for a session, oldest first.
   *
   * @return string[]
   *   The messages.
   */
  private function messages(AiEditorialSessionInterface $session): array {
    return array_map(
      static fn (AiEditorialSessionActivityInterface $entry): string => $entry->getMessage(),
      $session->getLatestActivity(100),
    );
  }

}
