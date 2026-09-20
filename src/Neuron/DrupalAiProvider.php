<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\UniqueIdGenerator;

/**
 * Neuron AI provider that delegates every model call to a drupal/ai provider.
 */
final class DrupalAiProvider implements AIProviderInterface {

  use HandleWithTools;

  /**
   * The system prompt of the next call, or NULL for none.
   */
  private ?string $system = NULL;

  /**
   * The message mapper, created on first use.
   */
  private DrupalAiMessageMapper $messageMapper;

  /**
   * The tool mapper, created on first use.
   */
  private DrupalAiToolMapper $toolMapper;

  /**
   * DrupalAiProvider constructor.
   *
   * @param object $provider
   *   The drupal/ai chat provider, as returned by the provider plugin manager.
   * @param string $modelId
   *   The model to call on that provider.
   * @param array $tags
   *   The tags passed with every call, for drupal/ai event subscribers.
   */
  public function __construct(
    private readonly object $provider,
    private readonly string $modelId,
    private readonly array $tags = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function systemPrompt(?string $prompt): AIProviderInterface {
    $this->system = $prompt;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function messageMapper(): MessageMapperInterface {
    return $this->messageMapper ??= new DrupalAiMessageMapper();
  }

  /**
   * {@inheritdoc}
   */
  public function toolPayloadMapper(): ToolMapperInterface {
    return $this->toolMapper ??= new DrupalAiToolMapper();
  }

  /**
   * {@inheritdoc}
   *
   * The HTTP client is the drupal/ai provider's concern, so it is ignored.
   */
  public function setHttpClient(HttpClientInterface $client): AIProviderInterface {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function chat(Message ...$messages): Message {
    $output = $this->provider->chat($this->input($messages, FALSE), $this->modelId, $this->tags);
    return $this->toMessage($this->drain($output));
  }

  /**
   * {@inheritdoc}
   */
  public function stream(Message ...$messages): \Generator {
    $output = $this->provider->chat($this->input($messages, TRUE), $this->modelId, $this->tags);
    $normalized = $output->getNormalized();
    $messageId = UniqueIdGenerator::generateId('msg_');

    if (!$normalized instanceof StreamedChatMessageIteratorInterface) {
      $text = $normalized->getText();
      if ($text !== '') {
        yield new TextChunk($messageId, $text);
      }
      return $this->toMessage($output);
    }

    // A small buffer makes text deltas arrive in small chunks.
    $normalized->setMaxBufferSize(5);
    foreach ($normalized as $chunk) {
      $text = $chunk->getText();
      if ($text !== '') {
        yield new TextChunk($messageId, $text);
      }
    }
    // The iterator assembles the tool calls; the reconstructed output only
    // carries the text and the token usage.
    return $this->toMessage($normalized->reconstructChatOutput(), $normalized->getFinishReason(), $normalized->getTools());
  }

  /**
   * {@inheritdoc}
   */
  public function structured(array|Message $messages, string $class, array $response_schema): Message {
    $input = $this->input(is_array($messages) ? $messages : [$messages], FALSE);
    $input->setChatStructuredJsonSchema(['name' => $class, 'schema' => $response_schema]);
    $output = $this->provider->chat($input, $this->modelId, $this->tags);
    return $this->toMessage($this->drain($output));
  }

  /**
   * Builds the drupal/ai chat input for a list of Neuron messages.
   */
  private function input(array $messages, bool $streamed): ChatInput {
    $input = new ChatInput($this->messageMapper()->map($messages));
    $input->setStreamedOutput($streamed);
    if ($this->system !== NULL && $this->system !== '') {
      $input->setSystemPrompt($this->system);
    }
    if ($this->tools !== []) {
      $input->setChatTools(new ToolsInput($this->toolPayloadMapper()->map($this->tools)));
    }
    return $input;
  }

  /**
   * Consumes a streamed output the caller did not iterate.
   */
  private function drain(ChatOutput $output): ChatOutput {
    $normalized = $output->getNormalized();
    if (!$normalized instanceof StreamedChatMessageIteratorInterface) {
      return $output;
    }
    // The chunks only matter for the reconstruction that follows.
    iterator_to_array($normalized, FALSE);
    return $normalized->reconstructChatOutput();
  }

  /**
   * Converts a complete drupal/ai output into a Neuron message.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $output
   *   An output whose normalized value is a complete chat message.
   * @param string|null $finishReason
   *   The finish reason when the output cannot report it itself.
   * @param array|null $calls
   *   The requested tool calls when the output cannot report them itself.
   */
  private function toMessage(ChatOutput $output, ?string $finishReason = NULL, ?array $calls = NULL): AssistantMessage {
    /** @var \Drupal\ai\OperationType\Chat\ChatMessage $chatMessage */
    $chatMessage = $output->getNormalized();
    $text = $chatMessage->getText();

    $calls ??= $chatMessage->getTools() ?: [];
    if ($calls !== []) {
      $tools = [];
      foreach ($calls as $call) {
        $arguments = [];
        foreach ($call->getArguments() as $argument) {
          $arguments[$argument->getName()] = $argument->getValue();
        }
        $tools[] = $this->findTool($call->getName())
          ->setInputs($arguments)
          ->setCallId($call->getToolId());
      }
      $message = new ToolCallMessage($text !== '' ? $text : NULL, $tools);
    }
    else {
      $message = new AssistantMessage($text);
    }

    $usage = $output->getTokenUsage();
    $message->setUsage(new Usage(
      $usage->input ?? 0,
      $usage->output ?? 0,
      $usage->cached ?? 0,
      $usage->reasoning ?? 0,
    ));

    $raw = $output->getRawOutput();
    $finishReason ??= is_array($raw) ? ($raw['choices'][0]['finish_reason'] ?? NULL) : NULL;
    if ($finishReason !== NULL) {
      $message->setStopReason($finishReason);
    }
    return $message;
  }

}
