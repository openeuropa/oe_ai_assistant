<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Drives one whole drafting turn through the agent manager.
 *
 * The chat endpoint still runs the old tool loop; this only proves the new
 * agents and tools produce a correct turn and a correct transcript on their
 * own, ahead of ticket 3 wiring the endpoint onto them.
 *
 * @group oe_ai_assistant
 */
class DraftingTurnTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules([
      'entity_reference_revisions',
      'paragraphs',
      'link',
      'inline_entity_form',
      'oe_ai_assistant_test',
    ]);
    $this->installEntitySchema('paragraph');
    $this->installConfig(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
        'chat_with_structured_response' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();
  }

  /**
   * A turn that drafts one group ends with a versioned draft and a transcript.
   *
   * The news_default template exposes title, field_teaser and field_body as
   * a single main_fields group, so one drafting_draft_group call completes
   * the draft.
   */
  public function testWholeTurnDraftsAndVersionsTheGroup(): void {
    $session = $this->createSession($this->createUser());
    $session->set('template', 'news_default')->save();

    MockAiProvider::reset();
    // The drafting agent calls drafting_draft_group for the only group it
    // has.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_group', 'arguments' => '{"group":"main_fields"}'],
        ],
      ],
    ));
    // The main_fields sub-agent answers the group schema.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}], '
      . '"field_body": [{"value": "<p>B</p>", "format": "full_html"}]}',
    ));
    // The drafting agent reports the draft back to the editor.
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is ready.'));

    $agent = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('drafting', ['session' => $session]);
    $state = $agent->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    $this->assertSame('Draft 1 is ready.', $state->getMessage()?->getContent());

    $threads = $session->conversationThreads();
    $this->assertCount(2, $threads, 'The editor thread and the drafter run both belong to the session.');

    // The store stamps the session off the agent plugin, so a thread is found
    // by the agent that opened it rather than by the name it carries.
    $editorRows = array_filter(
      array_merge(...array_values($threads)),
      static fn (object $row): bool => (string) $row->get('agent_id')->value === 'drafting',
    );

    // The user message, the tool call, its result and the final answer.
    $roles = array_map(
      static fn (object $row): string => (string) $row->get('role')->value,
      array_values($editorRows),
    );
    $this->assertSame(['user', 'assistant', 'user', 'assistant'], $roles);

    $results = $session->conversationToolResults();
    $this->assertCount(1, $results);
    $this->assertSame('drafting_draft_group', $results[0]['name']);
    $this->assertSame([], $results[0]['result']['pending']);
    $this->assertSame([
      'title' => [['value' => 'T']],
      'field_teaser' => [['value' => 'S']],
      'field_body' => [['value' => '<p>B</p>', 'format' => 'full_html']],
    ], $results[0]['result']['fields']);

    $draft = $results[0]['result']['draft'];
    $this->assertSame(1, $draft['version']);
    $this->assertSame('news_default', $draft['context']['template']['id']);
    $this->assertSame($results[0]['result']['fields'], $draft['fields']);
  }

}
