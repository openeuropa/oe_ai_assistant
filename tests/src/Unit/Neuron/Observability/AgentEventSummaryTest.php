<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Observability;

use Drupal\oe_ai_assistant\Neuron\Observability\AgentEventSummary;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent event summaries.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventSummary
 */
class AgentEventSummaryTest extends TestCase {

  /**
   * @covers ::describe
   */
  public function testDescribesEventsInOneLine(): void {
    $answer = new AssistantMessage('Hi');
    $answer->setUsage(new Usage(12, 3));
    $request = new ToolCallMessage(NULL, [
      Tool::make('draft_group', 'Drafts.'),
      Tool::make('draft_group', 'Drafts.'),
    ]);
    $failed = Tool::make('get_content_schema', 'Reads.')->setResult('{"error":"Bundle is required."}');
    $done = Tool::make('get_content_schema', 'Reads.')->setResult('[]');

    $this->assertSame('run started', AgentEventSummary::describe('workflow-start', NULL));
    $this->assertSame('node StreamingNode started', AgentEventSummary::describe('workflow-node-start', new WorkflowNodeStart('NeuronAI\\Agent\\Nodes\\StreamingNode', new WorkflowState())));
    $this->assertSame('model call started', AgentEventSummary::describe('inference-start', NULL));
    $this->assertSame('model answered (12 in, 3 out tokens)', AgentEventSummary::describe('inference-stop', new InferenceStop(new UserMessage('x'), $answer)));
    $this->assertSame('model requested draft_group, draft_group', AgentEventSummary::describe('inference-stop', new InferenceStop(new UserMessage('x'), $request)));
    $calling = new ToolCalling(Tool::make('draft_group', 'Drafts.')->setInputs(['group' => 'main_fields']));
    $this->assertSame('calling draft_group {"group":"main_fields"}', AgentEventSummary::describe('tool-calling', $calling));
    $this->assertSame('get_content_schema failed: Bundle is required.', AgentEventSummary::describe('tool-called', new ToolCalled($failed)));
    $this->assertSame('get_content_schema returned', AgentEventSummary::describe('tool-called', new ToolCalled($done)));
    $rejected = new Validated('main_fields', '{}', ['a', 'b']);
    $this->assertSame('answer rejected, 2 schema violation(s)', AgentEventSummary::describe('structured-validated', $rejected));
    $this->assertSame('error: Boom', AgentEventSummary::describe('error', new AgentError(new \RuntimeException('Boom'))));
    $this->assertSame('tools bootstrapped', AgentEventSummary::describe('tools-bootstrapped', NULL));
  }

}
