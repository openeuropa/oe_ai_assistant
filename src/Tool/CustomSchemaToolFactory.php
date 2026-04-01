<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Tool;

use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Tool factory that uses pre-built Tool metadata.
 *
 * Falls back to a delegate factory for tools not registered
 * with explicit metadata. This allows plugin-defined tools
 * (like draft_content) to use custom JSON schemas that cannot
 * be derived from PHP reflection.
 */
class CustomSchemaToolFactory implements ToolFactoryInterface {

  /**
   * Map of class name to Tool metadata.
   *
   * @var array<string, \Symfony\AI\Platform\Tool\Tool>
   */
  private array $registry = [];

  /**
   * Constructs a CustomSchemaToolFactory.
   *
   * @param \Symfony\AI\Platform\Tool\Tool[] $toolMetadata
   *   Pre-built Tool metadata objects. Each is indexed by the
   *   ExecutionReference class name.
   * @param \Symfony\AI\Agent\Toolbox\ToolFactoryInterface $delegate
   *   Fallback factory for tools without explicit metadata.
   */
  public function __construct(
    array $toolMetadata,
    private readonly ToolFactoryInterface $delegate,
  ) {
    foreach ($toolMetadata as $tool) {
      $class = $tool->getReference()->getClass();
      $this->registry[$class] = $tool;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTool(object|string $reference): iterable {
    $class = is_object($reference)
      ? get_class($reference)
      : $reference;

    if (isset($this->registry[$class])) {
      yield $this->registry[$class];
      return;
    }

    yield from $this->delegate->getTool($reference);
  }

}
