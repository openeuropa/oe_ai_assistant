<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Providers\DrupalAi;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi\DrupalAiProvider;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Neuron provider that delegates to drupal/ai.
 *
 * A recording stub stands in for the drupal/ai provider plugin: it keeps the
 * chat input it received and answers with a canned output.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi\DrupalAiProvider
 */
class DrupalAiProviderTest extends TestCase {

  /**
   * Builds a provider over a stub answering with the given output.
   *
   * @return array
   *   The Neuron provider and the stub, for reading the recorded inputs.
   */
  private function provider(ChatOutput $reply): array {
    $stub = new class($reply) {

      /**
       * The recorded calls, each with the input, model and tags.
       */
      public array $calls = [];

      public function __construct(private readonly ChatOutput $reply) {}

      /**
       * Records the call and returns the canned output.
       */
      public function chat(ChatInput $input, string $model, array $tags = []): ChatOutput {
        $this->calls[] = [$input, $model, $tags];
        return $this->reply;
      }

    };
    return [new DrupalAiProvider($stub, 'model-x', ['drafting']), $stub];
  }

  /**
   * @covers ::chat
   */
  public function testChatMapsTextUsageAndStopReason(): void {
    [$provider, $stub] = $this->provider(new ChatOutput(
      new ChatMessage('assistant', 'Hello.'),
      ['choices' => [['finish_reason' => 'stop']]],
      [],
      new TokenUsageDto(3, 2, 5),
    ));

    $message = $provider->systemPrompt('Be brief.')->chat(new UserMessage('Hi'));

    $this->assertInstanceOf(AssistantMessage::class, $message);
    $this->assertNotInstanceOf(ToolCallMessage::class, $message);
    $this->assertSame('Hello.', $message->getContent());
    $this->assertSame(3, $message->getUsage()->inputTokens);
    $this->assertSame(2, $message->getUsage()->outputTokens);
    $this->assertSame('stop', $message->getMetadata('stop_reason'));

    [$input, $model, $tags] = $stub->calls[0];
    $this->assertSame('model-x', $model);
    $this->assertSame(['drafting'], $tags);
    $this->assertSame('Be brief.', $input->getSystemPrompt());
    $this->assertFalse($input->isStreamedOutput());
    $this->assertNull($input->getChatTools());
    $this->assertSame([['user', 'Hi']], array_map(
      fn (ChatMessage $m) => [$m->getRole(), $m->getText()],
      $input->getMessages(),
    ));
  }

  /**
   * @covers ::stream
   */
  public function testStreamOfCompleteOutputYieldsOneChunk(): void {
    [$provider] = $this->provider(new ChatOutput(new ChatMessage('assistant', 'All at once.'), [], []));

    $generator = $provider->stream(new UserMessage('Go'));
    $chunks = iterator_to_array($generator, FALSE);

    $this->assertCount(1, $chunks);
    $this->assertInstanceOf(TextChunk::class, $chunks[0]);
    $this->assertSame('All at once.', $chunks[0]->content);
    $this->assertSame('All at once.', $generator->getReturn()->getContent());
  }

  /**
   * @covers ::structured
   */
  public function testStructuredSendsTheSchema(): void {
    [$provider, $stub] = $this->provider(new ChatOutput(new ChatMessage('assistant', '{"title": "x"}'), [], []));

    $message = $provider->structured([new UserMessage('Draft')], 'main_fields', ['type' => 'object']);

    $this->assertSame('{"title": "x"}', $message->getContent());
    $schema = $stub->calls[0][0]->getChatStructuredJsonSchema();
    $this->assertSame('main_fields', $schema['name']);
    $this->assertSame(['type' => 'object'], $schema['schema']);
  }

  /**
   * @covers \Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi\MessageMapper::map
   */
  public function testMessageMapperSplitsBlocksAndToolResults(): void {
    [$provider] = $this->provider(new ChatOutput(new ChatMessage('assistant', ''), [], []));
    $tool = Tool::make('lookup', 'Looks things up.')->setCallId('call_1')->setResult('found');

    $mapped = $provider->messageMapper()->map([
      new UserMessage([new TextContent('[Editorial change] Tone changed'), new TextContent('Draft it')]),
      new ToolCallMessage(NULL, [$tool]),
      new ToolResultMessage([$tool]),
    ]);

    $this->assertSame(
      [['user', '[Editorial change] Tone changed'], ['user', 'Draft it'], ['assistant', ''], ['tool', 'found']],
      array_map(fn (ChatMessage $m) => [$m->getRole(), $m->getText()], $mapped),
    );
    $this->assertSame('call_1', $mapped[2]->getTools()[0]->getToolId());
    $this->assertSame('call_1', $mapped[3]->getToolsId());
  }

}
