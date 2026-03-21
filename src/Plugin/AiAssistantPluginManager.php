<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;

/**
 * Manages discovery and instantiation of AI Assistant plugins.
 *
 * Discovers plugin classes under the Plugin\AiEditorialAssistant namespace
 * that carry the AiEditorialAssistant attribute.
 */
class AiAssistantPluginManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/AiEditorialAssistant',
      $namespaces,
      $module_handler,
      AiAssistantPluginInterface::class,
      AiEditorialAssistant::class,
    );
    $this->alterInfo('ai_assistant_plugin_info');
    $this->setCacheBackend($cache, 'ai_assistant_plugins');
  }

}
