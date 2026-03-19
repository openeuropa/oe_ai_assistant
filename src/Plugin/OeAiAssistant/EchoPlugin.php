<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\OeAiAssistant;

use Drupal\oe_ai_assistant\Annotation\AiAssistantPlugin;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Echo plugin: streams back the request message word by word via SSE.
 *
 * This is a development/testing plugin that demonstrates the SSE streaming
 * pattern without requiring a real AI backend.
 */
#[AiAssistantPlugin(
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
    return new static($configuration, $plugin_id, $plugin_definition);
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
   * Each event is a JSON object matching the EchoStreamEvent schema:
   * { word: string, index: number, done: boolean }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a validated EchoRequest body.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   */
  public function stream(Request $request): StreamedResponse {
    $body = $this->decodeJsonBody($request);
    $message = $body['message'] ?? '';
    $words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY);

    return new StreamedResponse(function () use ($words): void {
      // Disable execution time limit for streaming.
      set_time_limit(0);

      $total = count($words);
      foreach ($words as $index => $word) {
        $event = [
          'word' => $word,
          'index' => $index,
          'done' => $index === $total - 1,
        ];

        echo 'data: ' . json_encode($event) . "\n\n";
        ob_flush();
        flush();

        // Simulate streaming delay.
        if ($index < $total - 1) {
          usleep(75000);
        }
      }
    }, 200, [
      'Content-Type' => 'text/event-stream',
      'Cache-Control' => 'no-cache',
      'X-Accel-Buffering' => 'no',
      'Connection' => 'keep-alive',
    ]);
  }

}
