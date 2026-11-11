<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Drush\Commands;

use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Prints what a drafting template asks a model to write.
 */
final class SchemaCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly DraftingSchemaProviderInterface $schemaProvider,
  ) {
    parent::__construct();
  }

  /**
   * Prints the drafting groups for a content type.
   *
   * @param string $bundle
   *   The content type machine name.
   * @param array $options
   *   The command options.
   */
  #[CLI\Command(name: 'oe_ai_assistant:schema', aliases: ['oe-ai:schema'])]
  #[CLI\Argument(name: 'bundle', description: 'The content type machine name, such as oe_news.')]
  #[CLI\Option(name: 'template', description: 'A drafting template id. Without one the latest enabled template is used.')]
  #[CLI\Option(name: 'out', description: 'A file to write to instead of printing.')]
  #[CLI\Usage(name: 'drush oe_ai_assistant:schema oe_news', description: 'Print the groups.')]
  #[CLI\Usage(name: 'drush oe_ai_assistant:schema oe_news --template=news_with_paragraphs', description: 'Print the groups of one template.')]
  public function schema(string $bundle, array $options = ['template' => NULL, 'out' => NULL]): void {
    $groups = $this->schemaProvider->groups('node', $bundle, $options['template']);
    $json = json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
