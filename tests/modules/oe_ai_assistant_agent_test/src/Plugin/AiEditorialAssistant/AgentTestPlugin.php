<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\Response\AiStreamedResponse;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\PromptCodeBlockExtractor\PromptCodeBlockExtractorInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test plugin for agent/sub-agent orchestration spike.
 *
 * Streams LLM responses as SSE events using the Vercel AI SDK
 * UI Message Stream v1 protocol.
 *
 * Note: this plugin uses AiStreamedResponse directly instead of the base
 * class's createSseResponse(). If the pattern proves sound after the spike,
 * createSseResponse() on AiAssistantPluginBase may be removed in favour of
 * AiStreamedResponse from drupal/ai.
 */
#[AiEditorialAssistant(
  id: 'agent_test',
  label: 'Agent Test',
  description: 'Test plugin for agent/sub-agent orchestration.',
)]
class AgentTestPlugin extends AiAssistantPluginBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager
   */
  protected FunctionCallPluginManager $functionCallManager;

  /**
   * The AI agent plugin manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager
   */
  protected AiAgentManager $aiAgentManager;

  /**
   * The code block extractor for parsing LLM JSON responses.
   *
   * @var \Drupal\ai\Service\PromptCodeBlockExtractor\PromptCodeBlockExtractorInterface
   */
  protected PromptCodeBlockExtractorInterface $codeBlockExtractor;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->aiProviderManager = $container->get('ai.provider');
    $instance->functionCallManager = $container->get('plugin.manager.ai.function_calls');
    $instance->aiAgentManager = $container->get('plugin.manager.ai_agents');
    $instance->codeBlockExtractor = $container->get('ai.prompt_code_block_extractor');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return [
      'chat' => $this->chat(...),
    ];
  }

  /**
   * Handles a chat request by calling the LLM and streaming the response.
   *
   * The LLM receives a draft_content tool. If it responds with text, the
   * text is streamed as SSE. If it calls draft_content, the plugin runs
   * the sub-agent orchestration loop.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a JSON body containing 'message'.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The SSE streaming response.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);
    $message = $body['message'] ?? '';

    // Build a ChatInput with the user's message and streaming enabled.
    $chatInput = new ChatInput([
      new ChatMessage('user', $message),
    ]);
    $chatInput->setStreamedOutput(TRUE);

    // Load the draft_content tool from the FunctionCall plugin manager
    // and attach it to the request so the LLM can call it.
    $draftTool = $this->functionCallManager->createInstance(
      'oe_ai_assistant_agent_test:draft_content'
    );
    $chatInput->setChatTools(new ToolsInput([$draftTool->normalize()]));

    // System prompt instructs the LLM when to use the tool.
    $chatInput->setSystemPrompt(
      'You are a content assistant. Chat with the user to understand '
      . 'what they want. When they explicitly ask you to draft, generate, '
      . 'or write content, call the draft_content tool. Do not call it '
      . 'unless the user explicitly requests content generation.'
    );

    // Get the default provider for chat from configuration.
    $defaults = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
    $provider = $this->aiProviderManager->createInstance($defaults['provider_id']);

    // Call the LLM (router call).
    $chatOutput = $provider->chat($chatInput, $defaults['model_id'], ['agent_test']);

    // Stream the response as SSE using AiStreamedResponse from drupal/ai.
    $response = new AiStreamedResponse(NULL, 200, [
      'Content-Type' => 'text/event-stream',
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);

    $response->setCallback(function () use ($chatOutput): void {
      set_time_limit(0);

      $messageId = bin2hex(random_bytes(16));
      $this->emitSseEvent('start', ['messageId' => $messageId]);

      // Stream the router LLM response and check for tool calls.
      $normalized = $chatOutput->getNormalized();
      $toolCalls = [];

      if ($normalized instanceof StreamedChatMessageIteratorInterface) {
        $this->emitSseEvent('start-step', []);
        foreach ($normalized as $chunk) {
          $text = $chunk->getText();
          if ($text !== '' && $text !== NULL) {
            $this->emitSseEvent('text-delta', ['textDelta' => $text]);
          }
        }
        $this->emitSseEvent('finish-step', []);
        // After iteration completes, tool calls are assembled.
        $toolCalls = $normalized->getTools();
      }
      else {
        $this->emitSseEvent('start-step', []);
        $text = $normalized->getText();
        if ($text !== '' && $text !== NULL) {
          $this->emitSseEvent('text-delta', ['textDelta' => $text]);
        }
        $this->emitSseEvent('finish-step', []);
        $toolCalls = $normalized->getTools() ?? [];
      }

      // Check if draft_content was called.
      $draftCall = NULL;
      foreach ($toolCalls as $tool) {
        if ($tool->getName() === 'draft_content') {
          $draftCall = $tool;
          break;
        }
      }

      if ($draftCall !== NULL) {
        $consolidated = $this->orchestrate($draftCall);
        $this->emitSseEvent('data-drafted-fields', $consolidated);
      }

      $this->emitSseEvent('finish', [
        'finishReason' => $draftCall ? 'tool_calls' : 'stop',
        'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
      ]);

      echo "data: [DONE]\n\n";
      flush();
    });

    return $response;
  }

  /**
   * Runs the sub-agent orchestration loop.
   *
   * Loads the oe_test_content_drafter agent config entity for each
   * schema fragment. The agent's system prompt (from config) provides
   * the generic instruction; the Task carries the per-call schema and
   * instructions. The default AI provider is resolved automatically
   * by the agent framework.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface $draftCall
   *   The draft_content tool call with instructions.
   *
   * @return array
   *   The consolidated draft object.
   */
  protected function orchestrate(ToolsFunctionOutputInterface $draftCall): array {
    // Target schema fragments (hardcoded for the spike).
    $fragments = [
      'main_fields' => '{"title": {"type": "string"}, "summary": {"type": "string"}}',
      'item_hero' => '{"type": {"type": "string", "enum": ["hero"]}, "heading": {"type": "string"}, "body": {"type": "string"}}',
      'item_text_block' => '{"type": {"type": "string", "enum": ["text_block"]}, "heading": {"type": "string"}, "body": {"type": "string"}}',
    ];

    // Extract instructions from the tool call arguments.
    $instructions = '';
    foreach ($draftCall->getArguments() as $arg) {
      if ($arg->getName() === 'instructions') {
        $instructions = $arg->getValue();
        break;
      }
    }

    // Emit a plan event listing all steps upfront, so the UI can
    // render the full task list before execution begins.
    $plan = [];
    foreach ($fragments as $stepId => $schema) {
      $plan[] = ['stepId' => $stepId, 'status' => 'pending'];
    }
    $this->emitSseEvent('data-plan', $plan);

    $results = [];

    foreach ($fragments as $stepId => $schema) {
      $this->emitSseEvent('start-step', ['stepId' => $stepId]);

      try {
        // Load a fresh instance of the sub-agent config entity.
        $agent = $this->aiAgentManager->createInstance('oe_test_content_drafter');

        // Set structured output on the agent entity so providers that
        // support it (Mistral, OpenAI) return clean JSON without
        // markdown fencing. This modifies the in-memory entity only.
        $agent->getAiAgentEntity()->set('structured_output_enabled', TRUE);
        $agent->getAiAgentEntity()->set('structured_output_schema', $schema);

        // Pass instructions as the Task. The schema is enforced via
        // structured output above.
        $task = new Task("Instructions: $instructions");
        $agent->setTask($task);

        // Run the agent. Provider resolves automatically from defaults.
        $solvability = $agent->determineSolvability();
        $fullText = '';
        if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
          $fullText = $agent->solve() ?? '';
        }
        elseif ($solvability === AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION) {
          $fullText = $agent->answerQuestion() ?? '';
        }

        // Emit the sub-agent result as text-delta.
        if ($fullText !== '') {
          $this->emitSseEvent('text-delta', ['textDelta' => $fullText]);
        }

        $this->emitSseEvent('finish-step', ['stepId' => $stepId]);

        // Parse the JSON result. Use drupal/ai's PromptCodeBlockExtractor
        // to handle markdown fencing (```json...```) that real providers
        // like Mistral often add despite system prompt instructions.
        $jsonText = $this->codeBlockExtractor->extract($fullText, 'json');
        $parsed = json_decode(trim($jsonText), TRUE);
        if (is_array($parsed)) {
          $results[$stepId] = $parsed;
        }
      }
      catch (\Exception $e) {
        $this->emitSseEvent('error', [
          'errorText' => $e->getMessage(),
          'step' => $stepId,
        ]);
        $this->emitSseEvent('finish-step', ['stepId' => $stepId]);
        break;
      }
    }

    // Consolidate: main_fields + items array.
    $consolidated = $results['main_fields'] ?? [];
    $consolidated['items'] = [];
    if (isset($results['item_hero'])) {
      $consolidated['items'][] = $results['item_hero'];
    }
    if (isset($results['item_text_block'])) {
      $consolidated['items'][] = $results['item_text_block'];
    }

    return $consolidated;
  }

  /**
   * Emits an SSE event in the Vercel AI SDK UI Message Stream format.
   *
   * @param string $type
   *   The event type (e.g. 'text-delta', 'start', 'finish').
   * @param array $data
   *   The event data payload.
   */
  protected function emitSseEvent(string $type, array $data): void {
    $json = json_encode(
      ['type' => $type, 'data' => $data],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    echo "data: $json\n\n";
    flush();
  }

}
