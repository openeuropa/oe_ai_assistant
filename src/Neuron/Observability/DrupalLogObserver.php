<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Observability;

use NeuronAI\Observability\ObserverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Writes every Neuron event to a Drupal logger channel.
 *
 * Neuron's own log observer hands the logger context arrays with integer
 * keys, which Drupal's placeholder parser rejects. Here the event payload
 * travels as one JSON placeholder instead.
 */
class DrupalLogObserver implements ObserverInterface {

  /**
   * DrupalLogObserver constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onEvent(string $event, object $source, mixed $data = NULL, ?string $branchId = NULL): void {
    // @todo Every event is logged with its payload at debug level. A
    //   follow-up will make the level and the selection of events
    //   configurable.
    $this->logger->log(LogLevel::DEBUG, 'Neuron @event from @source: @data', [
      '@event' => $event,
      '@source' => $source::class,
      '@data' => (string) json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
  }

}
