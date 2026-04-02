<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Echo plugin: streams back the request message word by word via SSE.
 *
 * This is a development/testing plugin that demonstrates the SSE streaming
 * pattern without requiring a real AI backend. Uses custom data events
 * via the emitEvent() helper from AiAssistantPluginBase.
 *
 * The plugin exposes a single action:
 *   /api/ai/plugins/echo/stream -- accepts a message and echoes it back
 *                                   word by word with a small delay.
 */
#[AiEditorialAssistant(
  id: 'echo',
  label: 'Echo',
  description: 'Echoes the input message back as a word-by-word SSE stream.',
)]
class EchoPlugin extends AiAssistantPluginBase {

  /**
   * {@inheritdoc}
   *
   * This plugin has no additional service dependencies beyond what the base
   * class provides, so no container wiring is needed.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   *
   * @return static
   *   A new instance of this plugin.
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
   *
   * Maps the "stream" action to the stream() method. This is the only
   * action this plugin supports.
   *
   * @return array<string, callable>
   *   Map of action name to callable.
   */
  public function getActionMap(): array {
    return [
      'stream' => $this->stream(...),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Maps the "stream" action to its JSON Schema for request validation.
   * The EchoRequest schema requires a non-empty "message" string.
   *
   * @return array<string, string>
   *   Map of action name to schema name.
   */
  public function getRequestSchemas(): array {
    return [
      'stream' => 'EchoRequest',
    ];
  }

  /**
   * Streams the message back word by word as SSE events.
   *
   * Splits the incoming message on whitespace and emits one data-echo
   * event per word. Each event carries the word, its zero-based index,
   * and a "done" flag set to TRUE on the last word.
   *
   * A 75 ms delay is inserted between words to simulate the cadence of a
   * real LLM token stream. The delay is skipped for the final word.
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

    // Split the message into tokens on any run of whitespace.
    // PREG_SPLIT_NO_EMPTY ensures consecutive spaces do not produce
    // empty string tokens.
    $words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY);

    return $this->createSseResponse(function () use ($words): void {
      // Disable PHP's execution time limit for the streaming callback.
      set_time_limit(0);

      $total = count($words);
      foreach ($words as $index => $word) {
        // Emit a data-echo event. The frontend's Echo plugin listens
        // for this event type and renders each word progressively.
        $last = $index === $total - 1;
        $this->emitEvent('data-echo', [
          'data' => [
            'word' => $word,
            'index' => $index,
            // Signal to the frontend that no more words will follow.
            'done' => $last,
          ],
        ]);

        // Simulate per-token streaming delay between words. Skip the delay
        // for the last word so the stream closes promptly.
        if (!$last) {
          // 75 000 microseconds = 75 ms per word.
          usleep(75000);
        }
      }

      $this->emitDone();
    });
  }

}
