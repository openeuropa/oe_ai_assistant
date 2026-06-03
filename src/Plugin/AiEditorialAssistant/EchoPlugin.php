<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * Echo plugin: streams back the request message word by word via SSE.
 *
 * Development/testing plugin that demonstrates SSE streaming without
 * a real AI backend.
 */
#[AiEditorialAssistant(
  id: 'echo',
  label: 'Echo',
  description: 'Echoes the input message back as a word-by-word SSE stream.',
)]
class EchoPlugin extends AiAssistantPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return parent::create($container, $configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return [
      'stream' => $this->stream(...),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    return [
      'stream' => 'EchoRequest',
    ];
  }

  /**
   * Streams the message back word by word as SSE events.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a validated EchoRequest body.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The SSE streaming response.
   */
  public function stream(Request $request): Response {
    $body = $this->decodeJsonBody($request);
    $message = $body['message'] ?? '';
    $words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY);

    return $this->createSseResponse(function ($response) use ($words): void {
      set_time_limit(0);

      $total = count($words);
      foreach ($words as $index => $word) {
        $last = $index === $total - 1;
        $json = json_encode([
          'type' => 'data-echo',
          'data' => [
            'word' => $word,
            'index' => $index,
            'done' => $last,
          ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response->sendEvent(new ServerEvent($json));

        if (!$last) {
          usleep(75000);
        }
      }

      $response->sendEvent(new ServerEvent('[DONE]'));
    });
  }

  /**
   * Creates an SSE EventStreamResponse.
   *
   * Uses Symfony's EventStreamResponse which handles SSE headers,
   * output buffer flushing, and connection management natively.
   * The callback is wrapped to disable gzip compression.
   *
   * @param callable $callback
   *   The streaming callback. Use the EventStreamResponse's
   *   sendEvent() method to emit ServerEvent instances.
   *
   * @return \Symfony\Component\HttpFoundation\EventStreamResponse
   *   The configured SSE response.
   */
  private function createSseResponse(callable $callback): EventStreamResponse {
    $wrappedCallback = function (EventStreamResponse $response) use ($callback) {
      ini_set('zlib.output_compression', '0');
      ini_set('implicit_flush', '1');
      if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
      }
      $result = ($callback)($response);
      if (is_iterable($result)) {
        yield from $result;
      }
    };

    return new EventStreamResponse($wrappedCallback, 200, [
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);
  }

}
