<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Drush\Commands;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Service\TemplateSchemaFilterInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for inspecting drafting schemas.
 */
class SchemaCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityJsonSchemaComposer $composer,
    private readonly TemplateSchemaFilterInterface $filter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct();
  }

  /**
   * Prints the drafting schema for a content type, optionally template-pruned.
   *
   * Without --template the full composed schema is printed; with --template it
   * is pruned to that template's fields. With --groups the schema is shown as
   * the sub-agent groups the orchestrator dispatches. Useful for inspecting the
   * payload size sent to the LLM.
   */
  #[CLI\Command(name: 'oe_ai_assistant:schema', aliases: ['oe-ai:schema'])]
  #[CLI\Argument(name: 'bundle', description: 'The bundle machine name (e.g. oe_news, landing_page).')]
  #[CLI\Option(name: 'entity-type', description: 'The entity type ID.')]
  #[CLI\Option(name: 'template', description: 'A drafting template id; prunes the schema to its fields.')]
  #[CLI\Option(name: 'groups', description: 'Output the sub-agent groups instead of the raw schema.')]
  #[CLI\Usage(name: 'drush oe_ai_assistant:schema landing_page', description: 'Full composed schema.')]
  #[CLI\Usage(name: 'drush oe_ai_assistant:schema landing_page --template=ewcms_landing_page', description: 'Template-pruned schema.')]
  #[CLI\Usage(name: 'drush oe_ai_assistant:schema landing_page --template=ewcms_landing_page --groups', description: 'Template-pruned sub-agent groups.')]
  public function schema(string $bundle, array $options = ['entity-type' => 'node', 'template' => '', 'groups' => FALSE]): void {
    $entityTypeId = (string) $options['entity-type'];

    // Fail legibly on a bad bundle rather than composing a phantom schema.
    $bundles = $this->bundleInfo->getBundleInfo($entityTypeId);
    if (!isset($bundles[$bundle])) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown %s bundle "%s". Available: %s.',
        $entityTypeId,
        $bundle,
        $bundles === [] ? '(none)' : implode(', ', array_keys($bundles)),
      ));
    }

    $schema = $this->composer->compose($entityTypeId, $bundle);

    $template = NULL;
    $templateId = (string) $options['template'];
    if ($templateId !== '') {
      $template = $this->entityTypeManager
        ->getStorage('ai_drafting_template')
        ->load($templateId);
      if (!$template instanceof AiDraftingTemplateInterface) {
        throw new \InvalidArgumentException(sprintf('Drafting template "%s" not found.', $templateId));
      }
      // Reject a template built for a different content type: applying it would
      // prune against this bundle but label against the template's own type.
      if ($template->getContentType() !== $bundle) {
        throw new \InvalidArgumentException(sprintf(
          'Template "%s" targets content type "%s", not "%s".',
          $templateId,
          $template->getContentType(),
          $bundle,
        ));
      }
    }

    if ($options['groups']) {
      $output = $template !== NULL
        ? $this->filter->splitIntoGroups($schema, $template)
        : $this->composer->splitSchemaIntoGroups($entityTypeId, $bundle);
    }
    else {
      $output = $template !== NULL ? $this->filter->filter($schema, $template) : $schema;
    }

    $this->output()->writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Size summary on stderr (so piping the JSON stays clean), measured on the
    // compact encoding since that better reflects the payload sent to the LLM.
    $compact = strlen(json_encode($output, JSON_UNESCAPED_SLASHES));
    $this->logger()->notice(dt('Schema size: @bytes bytes compact (~@tokens tokens, rough).', [
      '@bytes' => $compact,
      '@tokens' => intdiv($compact, 4),
    ]));
  }

}
