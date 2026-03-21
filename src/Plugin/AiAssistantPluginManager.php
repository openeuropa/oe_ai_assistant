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
 * This class extends Drupal's DefaultPluginManager to wire up the correct
 * sub-namespace, interface, and attribute annotation for AI Assistant plugins.
 *
 * Discovery
 * ---------
 * Plugin classes are discovered inside the Plugin/AiEditorialAssistant sub-
 * namespace of every module. A class is recognised as a plugin when it
 * carries the AiEditorialAssistant PHP attribute (or the equivalent
 * Annotation\AiEditorialAssistant Doctrine annotation in older code).
 *
 * All discovered plugins must implement AiAssistantPluginInterface. The
 * plugin manager enforces this through the interface type passed to the
 * parent constructor.
 *
 * Caching
 * -------
 * Discovery results are cached under the 'ai_assistant_plugins' bin entry
 * so that the file system is not scanned on every request. The cache is
 * invalidated automatically when the module list changes (handled by
 * DefaultPluginManager).
 *
 * Alter hooks
 * -----------
 * Third-party modules can modify the discovered plugin definitions via the
 * hook_ai_assistant_plugin_info_alter() hook, which is registered through
 * the alterInfo() call in the constructor.
 *
 * Usage
 * -----
 * The plugin manager is registered as a service in oe_ai_assistant.services.yml
 * under the id plugin.manager.ai_assistant. The PluginController injects it
 * to load the plugin instance for each incoming API request.
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase
 * @see \Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant
 */
class AiAssistantPluginManager extends DefaultPluginManager {

  /**
   * Constructs an AiAssistantPluginManager instance.
   *
   * Passes all required arguments to DefaultPluginManager so that Drupal's
   * plugin discovery infrastructure knows where to look for plugins, which
   * interface they must implement, and which attribute/annotation identifies
   * them.
   *
   * @param \Traversable $namespaces
   *   An iterator of PSR-4 namespace => directory mappings provided by the
   *   Drupal kernel. DefaultPluginManager uses this to build the list of
   *   directories to scan for plugin classes.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend used to store serialised plugin definitions between
   *   requests. Drupal's default cache service is injected here.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler, used both to invoke alter hooks and to build the
   *   set of namespace-to-directory mappings for enabled modules.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      // Sub-namespace within each module where plugin classes live.
      // For example: src/Plugin/AiEditorialAssistant/Drafting.php.
      'Plugin/AiEditorialAssistant',
      $namespaces,
      $module_handler,
      // Interface that every discovered class must implement. The manager
      // throws an exception during instantiation if this contract is violated.
      AiAssistantPluginInterface::class,
      // PHP attribute (or Doctrine annotation) that marks a class as a plugin
      // and carries the plugin ID and label.
      AiEditorialAssistant::class,
    );

    // Register the alter hook name. Modules can implement
    // hook_ai_assistant_plugin_info_alter(&$definitions) to add, remove, or
    // modify plugin definitions after discovery.
    $this->alterInfo('ai_assistant_plugin_info');

    // Store discovered definitions in the 'ai_assistant_plugins' cache key
    // so that class-file scanning only happens once per cache lifetime.
    $this->setCacheBackend($cache, 'ai_assistant_plugins');
  }

}
