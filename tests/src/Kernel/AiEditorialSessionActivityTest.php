<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\oe_ai_assistant\Controller\SessionActivityController;
use Drupal\oe_ai_assistant\Entity\ActivityType;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Plugin\NeuronAgent\GetSessionActivityNeuronAgent;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use NeuronAI\Agent\Agent;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the activity record of an editorial session.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionActivityTest extends AiEditorialSessionKernelTestBase {

  /**
   * Creating a session records the tone and the template it was created with.
   */
  public function testCreatingSessionRecordsWhatItWasCreatedWith(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $tone = $this->createTone('Formal');
    $this->createTemplate('news_a', 'News A');

    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $owner->id(),
        'content_type' => 'oe_news',
        'tone' => $tone->id(),
        'template' => 'news_a',
      ]);
    $session->save();

    $this->assertSame(
      ['Session created with tone "Formal" and template "News A".'],
      $this->messages($session),
    );
    $this->assertSame([ActivityType::SessionCreated], $this->types($session));
    $this->assertSame((int) $owner->id(), (int) $session->getLatestActivity()[0]->getOwnerId());
  }

  /**
   * A session created without a tone or a template records both as not set.
   */
  public function testCreatingSessionReportsWhatTheEditorLeftUnset(): void {
    $session = $this->createSession($this->createUser());

    $this->assertSame(
      ['Session created with tone not set and template not set.'],
      $this->messages($session),
    );
  }

  /**
   * An entry records its session, its author, its message and its time.
   */
  public function testEntryRecordsWhatHappened(): void {
    $owner = $this->createUser();
    $session = $this->freshSession($owner);
    $this->container->get('current_user')->setAccount($owner);

    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "%s".', ['Formal']);

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
    $session = $this->freshSession($owner);
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
    $session = $this->freshSession($owner);
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
    $session = $this->freshSession($this->createUser());
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
    $session = $this->freshSession($owner);
    $other = $this->freshSession($owner);

    $session->recordActivity(ActivityType::ToneChanged, 'mine');
    $other->recordActivity(ActivityType::ToneChanged, 'theirs');

    $this->assertSame(
      ['mine'],
      array_map(
        static fn (AiEditorialSessionActivityInterface $entry): string => $entry->getMessage(),
        $session->getLatestActivity(100),
      ),
    );
    $this->assertCount(1, $session->getLatestActivity());
    $this->assertCount(1, $other->getLatestActivity());
  }

  /**
   * Deleting a session takes its entries with it, and leaves the others.
   */
  public function testDeletingSessionDeletesItsEntries(): void {
    $owner = $this->createUser();
    $session = $this->freshSession($owner);
    $other = $this->freshSession($owner);
    $session->recordActivity(ActivityType::ToneChanged, 'mine');
    $other->recordActivity(ActivityType::ToneChanged, 'theirs');

    $session->delete();

    $this->assertCount(0, $session->getLatestActivity());
    $this->assertCount(1, $other->getLatestActivity());
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
    $session = $this->freshSession($owner);
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
    $session = $this->freshSession($owner);
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
   * The prompt carries what happened in the session, who did it and when.
   */
  public function testAgentPromptCarriesTheActivityOfTheSession(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->freshSession($owner);
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');

    $this->assertStringContainsString(
      sprintf(
        '%s,you,"Tone set to ""Formal""."',
        gmdate('c', $session->getLatestActivity()[0]->getCreatedTime()),
      ),
      $this->activityPrompt($session),
    );
  }

  /**
   * The record calls the editor asking "you" and names everyone else.
   *
   * An editor asks what they themselves did. A name on its own does not
   * tell the model which of the names is theirs.
   */
  public function testAgentPromptNamesTheEditorAsking(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $session = $this->createSession($owner);

    $this->container->get('current_user')->setAccount($other);
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');
    $this->container->get('current_user')->setAccount($owner);
    $session->recordActivity(ActivityType::ToneChanged, 'Tone changed to "Technical".');

    $prompt = $this->activityPrompt($session);

    $this->assertStringContainsString(
      sprintf(',%s,"Tone set to ""Formal""."', $other->getDisplayName()),
      $prompt,
    );
    $this->assertStringContainsString(',you,"Tone changed to ""Technical""."', $prompt);
  }

  /**
   * The prompt of a session nothing happened in says the record is empty.
   */
  public function testAgentPromptReportsAnEmptyRecord(): void {
    $session = $this->freshSession($this->createUser());

    $this->assertStringContainsString(
      'The record is empty.',
      $this->activityPrompt($session),
    );
  }

  /**
   * The prompt carries every entry of the record, however long it is.
   *
   * The oldest entry says what the session was created with. Without it the
   * agent can't name the tone or the template of a session nothing has
   * changed since.
   */
  public function testAgentPromptCarriesEveryEntryOfTheRecord(): void {
    $session = $this->freshSession($this->createUser());
    $total = 250;
    for ($i = 1; $i <= $total; $i++) {
      $session->recordActivity(ActivityType::ToneChanged, 'Entry %d', [$i]);
    }

    $prompt = $this->activityPrompt($session);

    $this->assertStringContainsString(',"Entry 1"' . "\n", $prompt);
    $this->assertStringContainsString(sprintf(',"Entry %d"', $total) . "\n", $prompt);
    $this->assertSame($total, substr_count($prompt, ',"Entry '));
  }

  /**
   * The prompt carries the record of the session it serves, and no other.
   */
  public function testAgentPromptReadsOnlyItsOwnSession(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $this->createSession($owner)->recordActivity(ActivityType::ToneChanged, 'theirs');
    $session->recordActivity(ActivityType::ToneChanged, 'mine');

    $prompt = $this->activityPrompt($session);

    $this->assertStringContainsString(',mine', $prompt);
    $this->assertStringNotContainsString('theirs', $prompt);
  }

  /**
   * Each run of the answering agent opens a conversation of its own.
   *
   * A reused thread would replay the record we already sent, which is the
   * cost the agent exists to avoid.
   */
  public function testEachRunOpensItsOwnThread(): void {
    $session = $this->createSession($this->createUser());

    $this->assertNotSame(
      $this->activityAgent($session)->getThreadId(),
      $this->activityAgent($session)->getThreadId(),
    );
  }

  /**
   * The tool hands the question to the agent and returns the answer.
   */
  public function testToolReturnsTheAnswerOfTheAgent(): void {
    $this->mockChatProvider();
    MockAiProvider::enqueue(new MockResponse(text: 'You picked Formal first.'));
    $session = $this->createSession($this->createUser());
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');

    $tool = $this->container->get(NeuronToolManagerInterface::class)->createTool(
      'context_get_session_activity',
      ['session' => $session],
    );

    $this->assertSame(
      ['answer' => 'You picked Formal first.'],
      json_decode((string) $tool('Which tone did I pick first?'), TRUE),
    );
  }

  /**
   * The editorial agent carries the tool and tells the model to use it.
   */
  public function testEditorialAgentCarriesTheActivityTool(): void {
    $this->enableModules(['entity_reference_revisions', 'paragraphs', 'link', 'inline_entity_form']);
    $this->installEntitySchema('paragraph');
    $this->config('ai.settings')
      ->set('default_providers', ['chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    $session = $this->createSession($this->createUser());

    $agent = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $session]);

    $names = array_map(static fn ($tool) => $tool->getName(), $agent->getTools());
    $this->assertContains('context_get_session_activity', $names);
    $this->assertStringContainsString(
      'context_get_session_activity',
      $agent->getInstructions()->getContent(),
    );
  }

  /**
   * The activity page lists every entry of the session, oldest first.
   */
  public function testActivityPageListsEveryEntry(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->freshSession($owner);
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "%s".', ['Formal']);
    $session->recordActivity(ActivityType::DraftGenerated, 'Draft %d generated.', [1]);

    $rows = $this->activityPage($session)['activity']['#rows'];

    $this->assertSame(
      ['Tone set to "Formal".', 'Draft 1 generated.'],
      array_column($rows, 4),
    );
    $this->assertSame(
      ['tone_changed', 'draft_generated'],
      array_column($rows, 2),
    );
    $this->assertSame(
      [$owner->getDisplayName(), $owner->getDisplayName()],
      array_column($rows, 3),
    );
  }

  /**
   * An entry whose author is gone leaves the page cacheable.
   *
   * Core reads a NULL dependency as one it cannot cache. It drops the page
   * to max-age 0 and raises a deprecation, so the page must leave out an
   * entry that has no owner to depend on.
   */
  public function testActivityPageSurvivesTheDeletedActor(): void {
    // Cancelling an account writes to users_data, which the base class
    // leaves out because no other test here deletes a user.
    $this->installSchema('user', ['users_data']);
    $owner = $this->createUser();
    $actor = $this->createUser();
    $session = $this->freshSession($owner);
    $this->container->get('current_user')->setAccount($actor);
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');
    $actor->delete();

    $build = $this->activityPage($session);

    $this->assertNotSame(0, $build['#cache']['max-age'] ?? NULL);
    $this->assertSame(['-'], array_column($build['activity']['#rows'], 3));
  }

  /**
   * A stored kind this release does not know leaves the page readable.
   *
   * Nothing validates the type field on save, so a row written by another
   * release can name a kind this one has no case for.
   */
  public function testEntryOfUnknownKindIsStillRead(): void {
    $session = $this->freshSession($this->createUser());
    $session->recordActivity(ActivityType::ToneChanged, 'Tone set to "Formal".');
    $entry = $session->getLatestActivity()[0];
    $entry->set('type', 'invented_by_a_later_release')->save();

    $this->assertNull($session->getLatestActivity()[0]->getType());
    $this->assertSame(
      ['invented_by_a_later_release'],
      array_column($this->activityPage($session)['activity']['#rows'], 2),
    );
  }

  /**
   * A caller with no account of its own names the user to record.
   *
   * An agent that runs in a queue worker has no account attached to the
   * request, so the entry would otherwise record the anonymous user.
   */
  public function testRecordingAgainstTheGivenUser(): void {
    $actor = $this->createUser();
    $session = $this->freshSession($this->createUser());
    $this->container->get('current_user')->setAccount($this->createUser());

    $session->recordActivity(
      ActivityType::DraftGenerated,
      'Draft %d generated.',
      [1],
      (int) $actor->id(),
    );

    $this->assertSame(
      (int) $actor->id(),
      (int) $session->getLatestActivity()[0]->getOwnerId(),
    );
  }

  /**
   * A format that does not match its arguments fails loudly.
   *
   * Recording swallows a storage failure, and a mistake in the caller
   * would disappear into the same log line.
   */
  public function testRecordingThrowsOnAnIncompleteFormat(): void {
    $session = $this->freshSession($this->createUser());

    $this->expectException(\ArgumentCountError::class);
    $session->recordActivity(ActivityType::DraftSaved, 'Draft %d saved as %s.', [1]);
  }

  /**
   * The activity page of a session with no entries shows a message and no row.
   */
  public function testActivityPageListsNoRowForAnEmptySession(): void {
    $table = $this->activityPage($this->freshSession($this->createUser()))['activity'];

    $this->assertSame([], $table['#rows']);
    $this->assertNotEmpty((string) $table['#empty']);
  }

  /**
   * The activity page asks for a permission and for access to the session.
   *
   * The permission alone says who reads an activity record, not whose. A
   * holder of it would otherwise walk the session ids and read them all.
   */
  public function testActivityPageChecksThePermissionAndTheSession(): void {
    $this->container->get('router.builder')->rebuild();

    $route = $this->container->get('router.route_provider')
      ->getRouteByName('entity.ai_editorial_session.activity');

    $this->assertSame(
      'access ai editorial session activity',
      $route->getRequirement('_permission'),
    );
    $this->assertSame(
      'ai_editorial_session.view',
      $route->getRequirement('_entity_access'),
    );
  }

  /**
   * Builds the activity page of a session.
   */
  private function activityPage(AiEditorialSessionInterface $session): array {
    return SessionActivityController::create($this->container)->view($session);
  }

  /**
   * Returns the instructions the answering agent runs with.
   */
  private function activityPrompt(AiEditorialSessionInterface $session): string {
    return $this->activityAgent($session)->getInstructions()->getContent();
  }

  /**
   * Builds the agent that answers questions about the session.
   */
  private function activityAgent(AiEditorialSessionInterface $session): Agent {
    $this->mockChatProvider();

    return $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent(GetSessionActivityNeuronAgent::PLUGIN_ID, ['session' => $session]);
  }

  /**
   * Points the chat operation at the mock provider.
   */
  private function mockChatProvider(): void {
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', ['chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    MockAiProvider::reset();
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
