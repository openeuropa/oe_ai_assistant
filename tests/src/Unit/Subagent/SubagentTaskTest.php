<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Subagent;

use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SubagentToolNode;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentInterface;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ids, checks and results of subagent tasks.
 */
#[Group('oe_ai_assistant')]
class SubagentTaskTest extends UnitTestCase {

  /**
   * Tests that a call names the same task on every pass, and no other call.
   */
  public function testTaskIdIsDeterministicPerCall(): void {
    $id = SubagentToolNode::taskId('editorial.session_1', 'run_a', 'call_1', 0);

    $this->assertMatchesRegularExpression('/^sub_[0-9a-f]{24}$/', $id);
    $this->assertSame($id, SubagentToolNode::taskId('editorial.session_1', 'run_a', 'call_1', 0));
    $this->assertNotSame($id, SubagentToolNode::taskId('editorial.session_1', 'run_b', 'call_1', 0));
    $this->assertNotSame($id, SubagentToolNode::taskId('editorial.session_1', 'run_a', 'call_2', 0));
    $this->assertNotSame($id, SubagentToolNode::taskId('editorial.session_1', 'run_a', 'call_1', 1));
    $this->assertNotSame($id, SubagentToolNode::taskId('editorial.session_2', 'run_a', 'call_1', 0));
  }

  /**
   * Tests that a blank prompt is refused, and any other one is accepted.
   */
  public function testDefinitionRefusesBlankPrompt(): void {
    $definition = new SubagentDefinition('drafter', $this->createMock(SubagentInterface::class), 'brief');

    $this->assertSame('Parameter "brief" must not be empty.', $definition->validate(new SubagentTask('sub_x', 'call_1', " \n ")));
    $this->assertNull($definition->validate(new SubagentTask('sub_x', 'call_1', 'Write about floods.')));
  }

  /**
   * Tests what the model reads for a workflow's answer.
   */
  public function testResultOfWorkflow(): void {
    $done = SubagentResult::fromWorkflow(['draft' => 'Draft 1', 'version' => 1]);
    $this->assertSame([
      'task_id' => 'sub_x',
      'subagent' => 'drafter',
      'status' => SubagentResult::COMPLETED,
      'draft' => 'Draft 1',
      'version' => 1,
    ], $done->toArray('sub_x', 'drafter'));

    $failed = SubagentResult::fromWorkflow(['error' => 'The draft failed.', 'group' => 'main_fields', 'version' => 2]);
    $this->assertSame(SubagentResult::FAILED, $failed->status);
    $this->assertSame('The draft failed.', $failed->toArray('sub_x', 'drafter')['error']);
  }

}
