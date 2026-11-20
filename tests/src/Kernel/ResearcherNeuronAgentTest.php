<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the researcher agent and its document tools.
 */
#[Group('oe_ai_assistant')]
class ResearcherNeuronAgentTest extends AiEditorialSessionKernelTestBase {

  use ResearchDocumentTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();
  }

  /**
   * Builds the researcher for a session.
   */
  private function agent(AiEditorialSessionInterface $session): object {
    return $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('researcher', ['session' => $session, 'task' => 'sub_abc']);
  }

  /**
   * Builds a tool plugin for a session and returns its Neuron tool.
   */
  private function tool(string $id, AiEditorialSessionInterface $session): ToolInterface {
    return $this->container->get(NeuronToolManagerInterface::class)
      ->createTool($id, ['session' => $session]);
  }

  /**
   * Runs a Neuron tool with inputs and returns its result.
   */
  private function runTool(ToolInterface $tool, array $inputs): string|ToolOutput {
    $tool->setInputs($inputs)->execute();

    return $tool->getResult();
  }

  /**
   * Tests the tools, the thread and the instructions of the agent.
   */
  public function testAgentBuild(): void {
    $session = $this->createSession($this->createUser());
    $this->createExtractedDocument($session, 'Annual report', 'SECRETEXTRACTTEXT here', 'About the year.');
    $agent = $this->agent($session);

    $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $agent->getTools());
    sort($names);
    $this->assertSame([
      'research_ask_editor',
      'research_list_documents',
      'research_read_document',
      'research_search_documents',
    ], $names);

    $this->assertSame('researcher.session_' . $session->id() . '.sub_abc', $agent->getThreadId());

    $instructions = $agent->getInstructions()->getContent();
    $this->assertStringContainsString('Annual report', $instructions);
    $this->assertStringContainsString('About the year.', $instructions);
    $this->assertStringContainsString('1 pages', $instructions);
    $this->assertStringNotContainsString('SECRETEXTRACTTEXT', $instructions);
    $this->assertStringContainsString('[doc:<document_id> p.<page>]', $instructions);
  }

  /**
   * Tests the results of the tools, for success and for error.
   */
  public function testTools(): void {
    $session = $this->createSession($this->createUser());
    $id = $this->createExtractedDocument($session, 'Report', 'The budget grew in 2024.', 'Summary.');

    $list = json_decode($this->runTool($this->tool('research_list_documents', $session), []), TRUE);
    $this->assertSame($id, $list['documents'][0]['document_id']);

    $read = $this->tool('research_read_document', $session);
    $page = json_decode($this->runTool($read, ['document_id' => $id, 'page' => 1]), TRUE);
    $this->assertSame('The budget grew in 2024.', $page['text']);
    foreach ([['document_id' => $id, 'page' => 2], ['document_id' => 9999, 'page' => 1]] as $inputs) {
      $result = $this->runTool($read, $inputs);
      $this->assertInstanceOf(ToolOutput::class, $result);
      $this->assertStringContainsString('"is_error":true', json_encode($result));
    }

    $search = $this->tool('research_search_documents', $session);
    $found = json_decode($this->runTool($search, ['query' => 'budget']), TRUE);
    $this->assertSame($id, $found['results'][0]['document_id']);
    $this->assertSame(1, $found['results'][0]['page']);
    $this->assertInstanceOf(ToolOutput::class, $this->runTool($search, ['query' => 'a b']));

    $ask = $this->tool('research_ask_editor', $session);
    $this->assertSame(1, $ask->getMaxRuns());
    $this->assertStringStartsWith(
      'Your question has been recorded and will be passed to the editor.',
      $this->runTool($ask, ['question' => 'Which year?']),
    );
  }

}
