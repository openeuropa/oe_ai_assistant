<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\ActivityType;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

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
   * Picking a tone records it, and picking the same one again records nothing.
   *
   * The entry names the tone by the label the editor saw. The test renames
   * "Formal" to "Institutional" afterwards, so an entry that read the label
   * off the term would say the new name.
   */
  public function testSetToneRecordsTheToneTheEditorPicked(): void {
    $owner = $this->createUser(['use oe ai assistant', 'view_update own sessions']);
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $formal = $this->createTone('Formal');
    $plain = $this->createTone('Plain');

    $this->setTone($session, (string) $formal->id());
    $this->setTone($session, (string) $plain->id());
    $this->setTone($session, (string) $plain->id());

    $formal->setName('Institutional')->save();

    $this->assertSame([
      'Tone set to "Formal".',
      'Tone changed from "Formal" to "Plain".',
    ], $this->messages($session));
    $this->assertSame(
      [ActivityType::ToneChanged, ActivityType::ToneChanged],
      $this->types($session),
    );
  }

  /**
   * Picking a template records it, and changing it records the change.
   */
  public function testSetTemplateRecordsTheTemplateTheEditorPicked(): void {
    $owner = $this->createUser(['use oe ai assistant', 'view_update own sessions']);
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $this->createTemplate('news_a', 'News A');
    $this->createTemplate('news_b', 'News B');

    $this->setTemplate($session, 'news_a');
    $this->setTemplate($session, 'news_b');

    $this->assertSame([
      'Template set to "News A".',
      'Template changed from "News A" to "News B".',
    ], $this->messages($session));
    $this->assertSame(
      [ActivityType::TemplateChanged, ActivityType::TemplateChanged],
      $this->types($session),
    );
  }











  /**
   * Runs the set-tone action of the drafting plugin.
   */
  private function setTone(AiEditorialSessionInterface $session, string $toneId): void {
    $this->action($session, 'set-tone', ['toneId' => $toneId]);
  }

  /**
   * Runs the set-template action of the drafting plugin.
   */
  private function setTemplate(AiEditorialSessionInterface $session, string $template): void {
    $this->action($session, 'set-template', ['template' => $template]);
  }

  /**
   * Runs one action of the drafting plugin against a session.
   */
  private function action(AiEditorialSessionInterface $session, string $action, array $body): void {
    $request = Request::create('/', 'POST', content: json_encode(
      ['sessionId' => (string) $session->id()] + $body,
    ));

    $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting')
      ->executeAction($action, $request);
  }

  /**
   * Creates a tone term with the prompt that set-tone requires.
   */
  private function createTone(string $label): TermInterface {
    $term = Term::create([
      'vid' => 'oe_ai_tone',
      'name' => $label,
      'field_oe_ai_prompt' => 'Write in a ' . strtolower($label) . ' voice.',
    ]);
    $term->save();

    return $term;
  }

  /**
   * Creates an enabled drafting template for the news content type.
   */
  private function createTemplate(string $id, string $label): void {
    $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template')
      ->create([
        'id' => $id,
        'label' => $label,
        'content_type' => 'oe_news',
        'fields' => ['title' => ['prompt' => 'The title.']],
      ])
      ->save();
  }

  /**
   * Reads back the types recorded for a session, oldest first.
   *
   * @return array
   *   The types.
   */
  private function types(AiEditorialSessionInterface $session): array {
    return array_map(
      static fn (AiEditorialSessionActivityInterface $entry): ActivityType => $entry->getType(),
      $session->getLatestActivity(100),
    );
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
