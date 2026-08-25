<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_test;

use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\StreamWrapper\PrivateStream;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers services required by the OE AI Assistant test module.
 */
class OeAiAssistantTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    if ($container->hasDefinition('stream_wrapper.private')) {
      return;
    }

    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

}
