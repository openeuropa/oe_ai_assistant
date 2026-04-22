<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
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
 * Conversation history is persisted via PrivateTempStore so context
 * accumulates across multiple chat turns before drafting.
 */
#[AiEditorialAssistant(
  id: 'agent_test',
  label: 'Agent Test',
  description: 'Test plugin for agent/sub-agent orchestration.',
)]
class AgentTestPlugin extends AiAssistantPluginBase {

  /**
   * The temp store collection name for conversation history.
   */
  protected const STORE_COLLECTION = 'oe_ai_assistant_agent_test';

  /**
   * The temp store key for the conversation thread.
   */
  protected const THREAD_KEY = 'conversation';

  /**
   * Maximum messages to retain in the conversation history.
   */
  protected const MAX_MESSAGES = 20;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

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
   * The private temp store factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

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
    $instance->aiAgentManager = $container->get('plugin.manager.ai_agents');
    $instance->uiMessageStream = $container->get('oe_ai_assistant.ui_message_stream');
    $instance->tempStoreFactory = $container->get('tempstore.private');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return [
      'chat' => $this->chat(...),
      'reset' => $this->reset(...),
    ];
  }

  /**
   * Handles a chat request by calling the LLM and streaming the response.
   *
   * Loads conversation history from the temp store, appends the user's
   * message, calls the LLM with the full history, and persists the
   * response. If the LLM calls draft_content, runs the sub-agent
   * orchestration loop with the full conversation as context.
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

    // Load conversation history and append the user's message.
    $history = $this->loadHistory();
    $history[] = new ChatMessage('user', $message);

    // Load the router agent config entity for system prompt and tools.
    $router = $this->aiAgentManager->createInstance('oe_test_router');

    // Build ChatInput with the full conversation history.
    $chatInput = new ChatInput($history);
    $chatInput->setStreamedOutput(TRUE);
    $chatInput->setSystemPrompt($router->getSystemPrompt());
    $functions = $router->getFunctions();
    if (!empty($functions['normalized'])) {
      $chatInput->setChatTools(new ToolsInput($functions['normalized']));
    }

    // Get the default provider and call the LLM directly for streaming.
    $defaults = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
    $provider = $this->aiProviderManager->createInstance($defaults['provider_id']);
    $chatOutput = $provider->chat($chatInput, $defaults['model_id'], ['agent_test']);

    // Stream the response using UiMessageStream.
    return $this->uiMessageStream->respond(function (UiMessageStreamInterface $stream) use ($chatOutput, $history): void {
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
        $consolidated = $this->orchestrate($stream, $draftCall, $history);
        $stream->customEvent('data-drafted-fields', $consolidated);

        // Persist the drafted result in history so subsequent turns
        // can reference it (e.g. "change the title").
        $history[] = new ChatMessage('assistant', json_encode($consolidated));
        $this->saveHistory($history);
      }
      else {
        // Persist the text response in history. After streaming, the
        // reconstructed ChatOutput has the assembled text.
        $reconstructed = $chatOutput->getNormalized();
        if ($reconstructed instanceof StreamedChatMessageIteratorInterface) {
          $output = $reconstructed->reconstructChatOutput();
          $responseText = $output->getNormalized()->getText() ?? '';
        }
        else {
          $responseText = $reconstructed->getText() ?? '';
        }
        if ($responseText !== '') {
          $history[] = new ChatMessage('assistant', $responseText);
        }
        $this->saveHistory($history);
      }

      $stream->finish($draftCall ? 'tool_calls' : 'stop');
    });
  }

  /**
   * Resets the conversation history.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array
   *   A confirmation response.
   */
  public function reset(Request $request): array {
    $store = $this->tempStoreFactory->get(static::STORE_COLLECTION);
    $store->delete(static::THREAD_KEY);
    return ['status' => 'ok'];
  }

  /**
   * Loads conversation history from the temp store.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The stored messages, or empty array for new conversations.
   */
  protected function loadHistory(): array {
    $store = $this->tempStoreFactory->get(static::STORE_COLLECTION);
    $data = $store->get(static::THREAD_KEY);
    if (is_array($data)) {
      // Reconstruct ChatMessage objects from stored arrays.
      return array_map(
        fn(array $m) => new ChatMessage($m['role'], $m['text']),
        $data,
      );
    }
    return [];
  }

  /**
   * Saves conversation history to the temp store.
   *
   * Trims to MAX_MESSAGES to bound storage size.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The messages to persist.
   */
  protected function saveHistory(array $history): void {
    // Trim to max messages.
    if (count($history) > static::MAX_MESSAGES) {
      $history = array_slice($history, -static::MAX_MESSAGES);
    }
    // Store as simple arrays for serialization.
    $data = array_map(
      fn(ChatMessage $m) => ['role' => $m->getRole(), 'text' => $m->getText()],
      $history,
    );
    $store = $this->tempStoreFactory->get(static::STORE_COLLECTION);
    $store->set(static::THREAD_KEY, $data);
  }

  /**
   * Runs the sub-agent orchestration loop.
   *
   * Loads the oe_test_content_drafter agent config entity for each
   * schema fragment. Passes the full conversation history to each
   * sub-agent so generated content reflects the user's context.
   *
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The SSE stream to emit events on.
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface $draftCall
   *   The draft_content tool call with instructions.
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The full conversation history.
   *
   * @return array
   *   The consolidated draft object.
   */
  protected function orchestrate(UiMessageStreamInterface $stream, ToolsFunctionOutputInterface $draftCall, array $history): array {
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

    // Serialize conversation history for sub-agent context.
    $conversationContext = '';
    foreach ($history as $msg) {
      $conversationContext .= $msg->getRole() . ': ' . $msg->getText() . "\n";
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
        $agent->getAiAgentEntity()->set('structured_output_enabled', TRUE);
        $agent->getAiAgentEntity()->set('structured_output_schema',
          json_encode(['name' => $stepId, 'schema' => $schema])
        );

        // Pass conversation history + instructions as the Task so the
        // sub-agent has full context of what the user discussed.
        $taskPrompt = "Conversation context:\n$conversationContext\n"
          . "Instructions: $instructions";
        $task = new Task($taskPrompt);
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

        // Parse the JSON result.
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
