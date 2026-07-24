<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Streaming;

use Drupal\ai\Service\PromptCodeBlockExtractor\PromptCodeBlockExtractor;
use Drupal\oe_ai_assistant\Service\UiMessageStream;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for UiMessageStream.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Service\UiMessageStream
 */
class UiMessageStreamTest extends TestCase {

  /**
   * The UiMessageStream instance under test.
   *
   * @var \Drupal\oe_ai_assistant\Streaming\UiMessageStream
   */
  protected UiMessageStream $stream;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->stream = new UiMessageStream(
      new PromptCodeBlockExtractor(),
      new NullLogger(),
    );
  }

  /**
   * Tests extractJson with raw JSON input.
   *
   * @covers ::extractJson
   */
  public function testExtractJsonRaw(): void {
    $result = $this->stream->extractJson('{"title": "Hello", "summary": "World"}');
    $this->assertEquals(['title' => 'Hello', 'summary' => 'World'], $result);
  }

  /**
   * Tests extractJson with markdown-fenced JSON.
   *
   * @covers ::extractJson
   */
  public function testExtractJsonMarkdownFenced(): void {
    $input = "```json\n{\"title\": \"Hello\", \"summary\": \"World\"}\n```";
    $result = $this->stream->extractJson($input);
    $this->assertEquals(['title' => 'Hello', 'summary' => 'World'], $result);
  }

  /**
   * Tests extractJson with markdown fencing and extra whitespace.
   *
   * @covers ::extractJson
   */
  public function testExtractJsonMarkdownFencedWithWhitespace(): void {
    $input = "  ```json\n  {\"type\": \"hero\", \"heading\": \"Test\"}\n  ```  ";
    $result = $this->stream->extractJson($input);
    $this->assertEquals(['type' => 'hero', 'heading' => 'Test'], $result);
  }

  /**
   * Tests extractJson with empty input returns NULL.
   *
   * @covers ::extractJson
   */
  public function testExtractJsonEmpty(): void {
    $this->assertNull($this->stream->extractJson(''));
    $this->assertNull($this->stream->extractJson('   '));
  }

  /**
   * Tests extractJson with invalid JSON returns NULL.
   *
   * @covers ::extractJson
   */
  public function testExtractJsonInvalid(): void {
    $this->assertNull($this->stream->extractJson('not json at all'));
    $this->assertNull($this->stream->extractJson('{broken json'));
  }

  /**
   * Tests extractJson with plain text containing no JSON returns NULL.
   *
   * @covers ::extractJson
   */
  public function testExtractJsonPlainText(): void {
    $this->assertNull($this->stream->extractJson('Here is some content about climate.'));
  }

  /**
   * Tests that emit produces correct SSE format.
   *
   * @covers ::start
   * @covers ::textDelta
   * @covers ::finish
   */
  public function testSseEventFormat(): void {
    ob_start();
    $this->stream->start('test-message-id');
    $this->stream->textDelta('Hello');
    $this->stream->finish('stop');
    $output = ob_get_clean();

    // Verify SSE framing.
    $this->assertStringContainsString("data: ", $output);
    $this->assertStringContainsString("[DONE]", $output);

    // Parse and verify event structure.
    $frames = array_filter(explode("\n\n", trim($output)));
    $events = [];
    foreach ($frames as $frame) {
      $data = str_replace('data: ', '', trim($frame));
      if ($data === '[DONE]') {
        continue;
      }
      $events[] = json_decode($data, TRUE);
    }

    $this->assertEquals('start', $events[0]['type']);
    $this->assertEquals('test-message-id', $events[0]['messageId']);
    $this->assertEquals('text-delta', $events[1]['type']);
    $this->assertEquals('Hello', $events[1]['textDelta']);
    $this->assertEquals('finish', $events[2]['type']);
    $this->assertEquals('stop', $events[2]['finishReason']);
  }

  /**
   * Tests customEvent emits the correct type and data.
   *
   * @covers ::customEvent
   */
  public function testCustomEvent(): void {
    ob_start();
    $this->stream->customEvent('data-plan', [
      ['stepId' => 'step1', 'status' => 'pending'],
      ['stepId' => 'step2', 'status' => 'pending'],
    ]);
    $output = ob_get_clean();

    $data = json_decode(str_replace('data: ', '', trim($output)), TRUE);
    $this->assertEquals('data-plan', $data['type']);
    $this->assertCount(2, $data['data']);
    $this->assertEquals('step1', $data['data'][0]['stepId']);
  }

  /**
   * Tests startStep and finishStep with step IDs.
   *
   * @covers ::startStep
   * @covers ::finishStep
   */
  public function testStepEvents(): void {
    ob_start();
    $this->stream->startStep('main_fields');
    $this->stream->finishStep('main_fields');
    $output = ob_get_clean();

    $frames = array_filter(explode("\n\n", trim($output)));
    $events = array_map(fn($f) => json_decode(str_replace('data: ', '', trim($f)), TRUE), $frames);

    $this->assertEquals('start-step', $events[0]['type']);
    $this->assertEquals('main_fields', $events[0]['stepId']);
    $this->assertEquals('finish-step', $events[1]['type']);
    $this->assertEquals('main_fields', $events[1]['stepId']);
  }

  /**
   * Tests that a callback failure is logged and degraded into an error event.
   *
   * A provider failure in the main tool loop is not caught anywhere below
   * respond(): it must reach this catch-all, which logs the real cause
   * server-side and emits only a generic error frame to the open SSE stream.
   *
   * Not covered here: that a real provider failure actually propagates up to
   * respond() uncaught (would need a kernel test with a failing provider),
   * and the sendContent() buffer clearing, which is bypassed below.
   *
   * @covers ::respond
   */
  public function testRespondLogsAndDegradesOnCallbackFailure(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        'Streaming callback failed: @message',
        ['@message' => 'Provider refused: quota exceeded'],
      );

    $stream = new UiMessageStream(new PromptCodeBlockExtractor(), $logger);
    $response = $stream->respond(
      function (UiMessageStreamInterface $stream): void {
        throw new \RuntimeException('Provider refused: quota exceeded');
      }
    );

    // Invoke the registered callback directly: sendContent() would first
    // close every output buffer (see AiStreamedResponse::sendContent()),
    // including the one capturing the SSE output here. The catch-all under
    // test lives inside the callback respond() registers.
    ob_start();
    ($response->getCallback())();
    $output = ob_get_clean();

    // The internal failure detail must never leak into the stream.
    $this->assertStringNotContainsString('quota exceeded', $output);

    $frames = array_filter(explode("\n\n", trim($output)));
    $events = [];
    foreach ($frames as $frame) {
      $data = str_replace('data: ', '', trim($frame));
      if ($data === '[DONE]') {
        continue;
      }
      $events[] = json_decode($data, TRUE);
    }

    $this->assertEquals('error', $events[0]['type']);
    $this->assertEquals(
      'The assistant request failed. Please try again.',
      $events[0]['errorText'],
    );
    $this->assertEquals('finish', $events[1]['type']);
    $this->assertEquals('error', $events[1]['finishReason']);
  }

  /**
   * Tests error event format.
   *
   * @covers ::error
   */
  public function testErrorEvent(): void {
    ob_start();
    $this->stream->error('Something failed', 'item_hero');
    $output = ob_get_clean();

    $data = json_decode(str_replace('data: ', '', trim($output)), TRUE);
    $this->assertEquals('error', $data['type']);
    $this->assertEquals('Something failed', $data['errorText']);
    $this->assertEquals('item_hero', $data['step']);
  }

}
