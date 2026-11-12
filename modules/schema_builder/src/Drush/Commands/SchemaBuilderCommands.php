<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Drush\Commands;

use Drupal\schema_builder\SchemaBuilderInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Prints the schema of a bundle so it can be read.
 */
final class SchemaBuilderCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly SchemaBuilderInterface $schemaBuilder,
  ) {
    parent::__construct();
  }

  /**
   * Prints the JSON Schema of one entity bundle.
   *
   * @param string $bundle
   *   The bundle machine name.
   * @param array $options
   *   The command options.
   */
  #[CLI\Command(name: 'schema-builder:dump', aliases: ['sad'])]
  #[CLI\Argument(name: 'bundle', description: 'The bundle machine name, such as oe_news.')]
  #[CLI\Option(name: 'entity-type', description: 'The entity type ID.')]
  #[CLI\Option(name: 'out', description: 'A file to write to instead of printing.')]
  #[CLI\Usage(name: 'drush schema-builder:dump oe_news', description: 'Print the schema.')]
  #[CLI\Usage(name: 'drush schema-builder:dump oe_news --out=/tmp/oe_news.json', description: 'Write the schema to a file.')]
  public function dump(string $bundle, array $options = ['entity-type' => 'node', 'out' => NULL]): void {
    $schema = $this->schemaBuilder->get($options['entity-type'], $bundle);
    $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($options['out'] === NULL) {
      $this->output()->writeln($json);
      return;
    }

    file_put_contents($options['out'], $json);
    $this->logger()->success(dt('Wrote @bytes bytes to @path.', [
      '@bytes' => strlen($json),
      '@path' => $options['out'],
    ]));
  }

}
