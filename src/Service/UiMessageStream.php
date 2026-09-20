<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\Response\AiStreamedResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes SSE events in the Vercel AI SDK UI Message Stream v1 protocol.
 *
 * This class encapsulates the SSE event format used by the Vercel AI SDK
 * (https://sdk.vercel.ai/docs/ai-sdk-ui/stream-protocol), providing a
 * clean API for plugins to emit streaming events without coupling to the
 * wire format. Plugins call high-level methods (start, textDelta, finish)
 * and this class handles JSON encoding, SSE framing, and flushing.
 *
 * Usage:
 * @code
 * $stream = \Drupal::service(UiMessageStreamInterface::class);
 * return $stream->respond(function ($stream) {
 *   $stream->start();
 *   $stream->startStep('main_fields');
 *   $stream->textDelta('Hello world');
 *   $stream->finishStep('main_fields');
 *   $stream->finish();
 * });
 * @endcode
 *
 * @see https://sdk.vercel.ai/docs/ai-sdk-ui/stream-protocol
 */
class UiMessageStream implements UiMessageStreamInterface {

  public function __construct(
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function respond(callable $callback): Response {
    $response = new AiStreamedResponse(NULL, 200, [
      'Content-Type' => 'text/event-stream',
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);

    $stream = $this;
    $response->setCallback(function () use ($callback, $stream): void {
      set_time_limit(0);
      try {
        $callback($stream);
      }
      catch (\Throwable $e) {
        // Never let an exception escape into the open SSE stream:
        // Drupal's exception handler would print an HTML error page
        // mid-stream. Log the details server-side and degrade into a
        // protocol error event so the client can render it cleanly.
        $this->logger->error('Streaming callback failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        $stream->error('The assistant request failed. Please try again.');
        $stream->finish('error');
      }
    });

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function start(?string $messageId = NULL): void {
    $messageId = $messageId ?? bin2hex(random_bytes(16));
    $this->emit('start', ['messageId' => $messageId]);
  }

  /**
   * {@inheritdoc}
   */
  public function startStep(string $stepId = ''): void {
    $data = $stepId !== '' ? ['stepId' => $stepId] : [];
    $this->emit('start-step', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function textDelta(string $text): void {
    if ($text === '') {
      return;
    }
    $this->emit('text-delta', ['textDelta' => $text]);
  }

  /**
   * {@inheritdoc}
   */
  public function finishStep(string $stepId = ''): void {
    $data = $stepId !== '' ? ['stepId' => $stepId] : [];
    $this->emit('finish-step', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function customEvent(string $type, array $data): void {
    $this->emit($type, ['data' => $data]);
  }

  /**
   * {@inheritdoc}
   */
  public function toolCall(string $toolName, array $args, array $result): void {
    $toolCallId = bin2hex(random_bytes(8));
    $this->emit('tool-call-start', [
      'id' => $toolCallId,
      'toolCallId' => $toolCallId,
      'toolName' => $toolName,
    ]);
    // Encode empty args as a JSON object, not an array, so the client parses
    // the arguments as an object.
    $this->emit('tool-call-delta', [
      'argsText' => json_encode($args ?: new \stdClass()),
    ]);
    $this->emit('tool-call-end', []);
    $this->emit('tool-result', [
      'toolCallId' => $toolCallId,
      'result' => $result,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function error(string $errorText, string $step = ''): void {
    $data = ['errorText' => $errorText];
    if ($step !== '') {
      $data['step'] = $step;
    }
    $this->emit('error', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function finish(string $finishReason = 'stop'): void {
    $this->emit('finish', [
      'finishReason' => $finishReason,
    ]);
    echo "data: [DONE]\n\n";
    flush();
  }

  /**
   * Emits a single SSE event.
   *
   * @param string $type
   *   The event type.
   * @param array $data
   *   The event data payload.
   */
  protected function emit(string $type, array $data): void {
    $data['type'] = $type;
    $json = json_encode(
      $data,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    echo "data: $json\n\n";
    flush();
  }

}
