<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Plugin\QueueWorker\DraftGroupQueueWorker;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the worker that drafts one field group of a draft.
 */
#[Group('oe_ai_assistant')]
class DraftGroupQueueWorkerTest extends AiEditorialSessionKernelTestBase {

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
   * Tests that a valid answer settles the job with the fields.
   */
  public function testValidAnswerFinishesTheJob(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $jobId = $this->recordJob($owner->id());

    MockAiProvider::enqueue(new MockResponse(text: '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}'));
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance(DraftGroupQueueWorker::QUEUE)
      ->processItem(['job_id' => $jobId]);

    $row = $this->container->get(DraftGroupJobs::class)->load($jobId);
    $this->assertSame(DraftGroupJobs::DONE, $row['status']);
    $this->assertSame('T', $row['result']['title'][0]['value']);
    $this->assertNotNull($row['progress']);
  }

  /**
   * Tests that a blocked owner fails the job without a provider call.
   */
  public function testBlockedOwnerFailsTheJob(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $jobId = $this->recordJob($owner->id());
    $owner->block()->save();

    $this->container->get('plugin.manager.queue_worker')
      ->createInstance(DraftGroupQueueWorker::QUEUE)
      ->processItem(['job_id' => $jobId]);

    $row = $this->container->get(DraftGroupJobs::class)->load($jobId);
    $this->assertSame(DraftGroupJobs::FAILED, $row['status']);
    $this->assertSame('The user of the task can no longer run it.', $row['error']);
    $this->assertSame([], MockAiProvider::getCallLog());
  }

  /**
   * Records the job of the main fields group of a new session.
   */
  private function recordJob(int|string $uid): string {
    $session = $this->createSession($this->container->get('entity_type.manager')->getStorage('user')->load($uid));
    assert($session instanceof ContentCreationSessionInterface);
    $group = $session->getFieldGroups()[0];
    $jobId = DraftGroupJobs::jobId('task', 'call', (string) $group['groupId']);
    $this->container->get(DraftGroupJobs::class)->record($jobId, (int) $session->id(), (int) $uid, 'task', 'call', (string) $group['groupId'], 'draft_1.x', [
      'schema' => $group['schemaSlice'],
      'task' => 'Write a news article.',
    ]);

    return $jobId;
  }

}
