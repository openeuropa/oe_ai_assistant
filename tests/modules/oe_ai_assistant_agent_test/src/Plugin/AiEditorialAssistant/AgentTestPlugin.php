<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test plugin for agent/sub-agent orchestration spike.
 *
 * Streams LLM responses as SSE events using the Vercel AI SDK
 * UI Message Stream v1 protocol via UiMessageStream.
 *
 * Note: this plugin uses UiMessageStream instead of the base class's
 * createSseResponse(). If the pattern proves sound after the spike,
 * createSseResponse() on AiAssistantPluginBase may be removed.
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
   * The UI message stream service.
   *
   * @var \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface
   */
  protected UiMessageStreamInterface $uiMessageStream;

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
    $instance->uiMessageStream = $container->get('Drupal\oe_ai_assistant\Service\UiMessageStream');
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

    // Stream the response using UiMessageStream.
    return $this->uiMessageStream->respond(function (UiMessageStreamInterface $stream) use ($chatOutput): void {
      $stream->start();

      // Stream the router response and collect tool calls.
      $toolCalls = $stream->streamChatOutput($chatOutput, 'router');

      // Check if draft_content was called.
      $draftCall = NULL;
      foreach ($toolCalls as $tool) {
        if ($tool->getName() === 'draft_content') {
          $draftCall = $tool;
          break;
        }
      }

      if ($draftCall !== NULL) {
        $consolidated = $this->orchestrate($stream, $draftCall);
        $stream->customEvent('data-drafted-fields', $consolidated);
      }

      $stream->finish($draftCall ? 'tool_calls' : 'stop');
    });
  }

  /**
   * Runs the sub-agent orchestration loop.
   *
   * Loads the oe_test_content_drafter agent config entity for each
   * schema fragment. Emits SSE events via the stream for progressive
   * feedback between sub-agent calls.
   *
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The SSE stream to emit events on.
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface $draftCall
   *   The draft_content tool call with instructions.
   *
   * @return array
   *   The consolidated draft object.
   */
  protected function orchestrate(UiMessageStreamInterface $stream, ToolsFunctionOutputInterface $draftCall): array {
    // Target schema fragments as valid JSON Schema (hardcoded for the
    // spike). Must include type/properties/required to satisfy strict
    // providers like OpenAI. Property descriptions serve as
    // micro-prompts that guide the LLM's output.
    $fragments = [
      'main_fields' => [
        'type' => 'object',
        'properties' => [
          'title' => [
            'type' => 'string',
            'maxLength' => 20,
            'description' => 'A short, punchy headline. Max 20 characters.',
          ],
          'summary' => [
            'type' => 'string',
            'maxLength' => 50,
            'description' => 'A one-sentence overview. Max 50 characters.',
          ],
        ],
        'required' => ['title', 'summary'],
        'additionalProperties' => FALSE,
      ],
      'item_hero' => [
        'type' => 'object',
        'properties' => [
          'type' => [
            'type' => 'string',
            'enum' => ['hero'],
          ],
          'heading' => [
            'type' => 'string',
            'maxLength' => 20,
            'description' => 'A short heading. Max 20 characters.',
          ],
          'body' => [
            'type' => 'string',
            'maxLength' => 50,
            'description' => 'Brief hero text. Max 50 characters.',
          ],
        ],
        'required' => ['type', 'heading', 'body'],
        'additionalProperties' => FALSE,
      ],
      'item_text_block' => [
        'type' => 'object',
        'properties' => [
          'type' => [
            'type' => 'string',
            'enum' => ['text_block'],
          ],
          'heading' => [
            'type' => 'string',
            'maxLength' => 20,
            'description' => 'A short heading. Max 20 characters.',
          ],
          'body' => [
            'type' => 'string',
            'maxLength' => 50,
            'description' => 'Brief paragraph text. Max 50 characters.',
          ],
        ],
        'required' => ['type', 'heading', 'body'],
        'additionalProperties' => FALSE,
      ],
    ];

    // Extract instructions from the tool call arguments.
    $instructions = '';
    foreach ($draftCall->getArguments() as $arg) {
      if ($arg->getName() === 'instructions') {
        $instructions = $arg->getValue();
        break;
      }
    }

    // Emit the plan upfront so the UI can show all pending steps.
    $plan = [];
    foreach ($fragments as $stepId => $schema) {
      $plan[] = ['stepId' => $stepId, 'status' => 'pending'];
    }
    $stream->customEvent('data-plan', $plan);

    $results = [];

    foreach ($fragments as $stepId => $schema) {
      $stream->startStep($stepId);

      try {
        // Load a fresh instance of the sub-agent config entity.
        $agent = $this->aiAgentManager->createInstance('oe_test_content_drafter');

        // Set structured output on the agent entity so providers that
        // support it return clean JSON without markdown fencing.
        // The config value is a JSON string that the agent wrapper
        // decodes. It must contain a 'schema' key as expected by
        // ChatInput::setChatStructuredJsonSchema().
        $agent->getAiAgentEntity()->set('structured_output_enabled', TRUE);
        $agent->getAiAgentEntity()->set('structured_output_schema',
          json_encode(['name' => $stepId, 'schema' => $schema])
        );

        // Pass instructions as the Task.
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
        $stream->textDelta($fullText);

        $stream->finishStep($stepId);

        // Parse the JSON result. extractJson() handles both raw JSON
        // and markdown-fenced JSON from providers that ignore
        // structured output.
        $parsed = $stream->extractJson($fullText);
        if (is_array($parsed)) {
          $results[$stepId] = $parsed;
        }
      }
      catch (\Exception $e) {
        $stream->error($e->getMessage(), $stepId);
        $stream->finishStep($stepId);
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

}
