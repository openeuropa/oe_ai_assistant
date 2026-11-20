<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\oe_ai_assistant\Plugin\QueueWorker\DraftGroupQueueWorker;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the drafter uses the groups a worker has already drafted.
 */
#[Group('oe_ai_assistant')]
class ParallelGroupsTest extends AiEditorialSessionKernelTestBase {

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
    MockAiProvider::reset();
  }

  /**
   * Tests that the draft takes the group a worker drafted, without a new call.
   */
  public function testDraftUsesTheGroupWorkerDrafted(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $session = $this->createSession($owner);
    $groupId = 'field_content_paragraphs';
    $group = array_values(array_filter($session->getFieldGroups(), static fn (array $g): bool => $g['groupId'] === $groupId))[0];

    $jobId = DraftGroupJobs::jobId('sub_parallel', 'call_1', $groupId);
    $this->container->get(DraftGroupJobs::class)->record($jobId, (int) $session->id(), (int) $owner->id(), 'sub_parallel', 'call_1', $groupId, 'draft_1.x', [
      'schema' => $group['schemaSlice'],
      'task' => 'Write a news article.',
    ]);
    MockAiProvider::enqueue(new MockResponse(text: '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>Worker</p>"}], "type": [{"target_id": "text_block"}]}]}'));
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance(DraftGroupQueueWorker::QUEUE)
      ->processItem(['job_id' => $jobId]);
    $this->assertCount(1, MockAiProvider::getCallLog());

    MockAiProvider::enqueue(new MockResponse(text: '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}'));
    $events = $this->container->get(NeuronWorkflowManagerInterface::class)
      ->createWorkflow('draft_content', [], [
        'session' => $session,
        'call' => 'call_1',
        'brief' => 'Draft it, go ahead.',
        'task' => 'sub_parallel',
      ])
      ->events();

    $drafted = [];
    foreach ($events as $event) {
      if ($event instanceof ActivityStreamEvent && $event->type === 'drafting' && $event->data['status'] === 'drafted') {
        $drafted[] = $event->data['group'];
      }
    }

    $this->assertContains($groupId, $drafted);
    $fields = $session->getDraftContent(1)['fields'];
    $this->assertSame('T', $fields['title'][0]['value']);
    $this->assertSame('<p>Worker</p>', $fields['field_content_paragraphs'][0]['field_text_body'][0]['value']);
    $this->assertTrue(MockAiProvider::isEmpty());
    $this->assertCount(2, MockAiProvider::getCallLog());
  }

}
