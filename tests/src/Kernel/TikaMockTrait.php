<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/**
 * Replaces the core HTTP client with a fake Tika server.
 */
trait TikaMockTrait {

  /**
   * Installs the fake server and returns its response queue.
   */
  protected function mockTika(): MockHandler {
    $handler = new MockHandler();
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($handler)]));
    return $handler;
  }

}
