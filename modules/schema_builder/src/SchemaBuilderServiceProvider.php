<?php

declare(strict_types=1);

namespace Drupal\schema_builder;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\schema_builder\SchemaBuilder\BuilderBase;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers every builder a module ships, without a service entry each.
 *
 * A builder that is never registered is never called, and nothing says so.
 * Any class under the SchemaBuilder directory of a module is registered
 * here, so writing the class is all it takes.
 */
final class SchemaBuilderServiceProvider implements ServiceProviderInterface {

  /**
   * The directory a module puts its builders in, under its own src.
   */
  private const DIRECTORY = 'SchemaBuilder';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    // A module's pathname is relative to the root of the installation. The
    // app.root parameter is still empty while the container is compiled, so
    // the constant the bootstrap defines is what there is to go on.
    foreach ($container->getParameter('container.modules') as $name => $module) {
      $root = DRUPAL_ROOT . '/' . dirname($module['pathname']) . '/src/' . self::DIRECTORY;
      if (!is_dir($root)) {
        continue;
      }
      foreach ($this->classes($root, 'Drupal\\' . $name . '\\' . self::DIRECTORY) as $class) {
        $this->registerBuilder($container, $class);
      }
    }
  }

  /**
   * Lists the classes a directory and the directories below it declare.
   */
  private function classes(string $directory, string $namespace): array {
    $classes = [];
    foreach (new \DirectoryIterator($directory) as $entry) {
      if ($entry->isDot()) {
        continue;
      }
      if ($entry->isDir()) {
        $classes = array_merge(
          $classes,
          $this->classes($entry->getPathname(), $namespace . '\\' . $entry->getFilename()),
        );
        continue;
      }
      if ($entry->getExtension() === 'php') {
        $classes[] = $namespace . '\\' . $entry->getBasename('.php');
      }
    }

    return $classes;
  }

  /**
   * Registers one class, when it is a builder that can be instantiated.
   */
  private function registerBuilder(ContainerBuilder $container, string $class): void {
    if (!class_exists($class) || $container->hasDefinition($class)) {
      return;
    }

    $reflection = new \ReflectionClass($class);
    if ($reflection->isAbstract() || !$reflection->isSubclassOf(BuilderBase::class)) {
      return;
    }

    // Autowiring reads the constructor. The tag is set here rather than left
    // to autoconfiguration, which only applies to definitions a services.yml
    // declares, and it is the tag that puts the builder on the events.
    // A definition built here is private by default, and a private service
    // nothing references is dropped before the subscriber pass sees it.
    // Drupal's own service files default to public, so this matches them.
    $definition = new Definition($class);
    $definition->setPublic(TRUE);
    $definition->setAutowired(TRUE);
    $definition->addTag('event_subscriber');
    $container->setDefinition($class, $definition);
  }

}
