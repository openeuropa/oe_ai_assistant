<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\oe_ai_assistant\Annotation\AiAssistantPlugin;

/**
 * Manages discovery and instantiation of AI Assistant plugins.
 *
 * Discovers plugin classes under the Plugin\OeAiAssistant namespace
 * that carry the AiAssistantPlugin attribute.
 */
class AiAssistantPluginManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/OeAiAssistant',
      $namespaces,
      $module_handler,
      AiAssistantPluginInterface::class,
      AiAssistantPlugin::class,
    );
    $this->alterInfo('ai_assistant_plugin_info');
    $this->setCacheBackend($cache, 'ai_assistant_plugins');
  }

}
