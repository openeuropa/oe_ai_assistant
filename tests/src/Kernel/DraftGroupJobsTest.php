<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the jobs that draft the field groups of a draft in parallel.
 */
#[Group('oe_ai_assistant')]
class DraftGroupJobsTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests that of two claimants only one wins the job.
   */
  public function testSecondClaimLoses(): void {
    $jobs = $this->container->get(DraftGroupJobs::class);
    $jobId = DraftGroupJobs::jobId('task', 'call', 'main_fields');
    $this->assertTrue($jobs->record($jobId, 1, NULL, 'task', 'call', 'main_fields', 'run', ['task' => 'x']));
    $this->assertFalse($jobs->record($jobId, 1, NULL, 'task', 'call', 'main_fields', 'run', ['task' => 'y']));

    $row = $jobs->claim($jobId, 'a');
    $this->assertSame(DraftGroupJobs::RUNNING, $row['status']);
    $this->assertSame(['task' => 'x'], $row['input']);
    $this->assertNull($jobs->claim($jobId, 'b'));
  }

  /**
   * Tests that an abandoned draft leaves its pending jobs unclaimable.
   */
  public function testAbandonedJobCannotBeClaimed(): void {
    $jobs = $this->container->get(DraftGroupJobs::class);
    $jobId = DraftGroupJobs::jobId('task', 'call', 'main_fields');
    $jobs->record($jobId, 1, NULL, 'task', 'call', 'main_fields', 'run', []);
    $jobs->abandonDraft('task', 'call');

    $this->assertNull($jobs->claim($jobId, 'a'));
    $this->assertSame(DraftGroupJobs::ABANDONED, $jobs->forDraft('task', 'call')['main_fields']['status']);
  }

}
