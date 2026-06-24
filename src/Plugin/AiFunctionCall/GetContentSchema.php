<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * FunctionCall plugin that returns the content type schema split into groups.
 *
 * The LLM calls this tool to discover what fields need to be drafted.
 * Delegates to EntityJsonSchemaComposer::splitSchemaIntoGroups() for
 * the actual splitting logic.
 */
#[FunctionCall(
  id: 'oe_ai_assistant:get_content_schema',
  function_name: 'get_content_schema',
  name: 'Get Content Schema',
  description: 'Returns the content type schema split into field groups. Call this to discover what fields need to be drafted and what information is needed from the user.',
  context_definitions: [
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Bundle"),
      description: new TranslatableMarkup("The content type bundle machine name (e.g. oe_news)."),
      required: TRUE,
    ),
    'entity_type_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Entity type ID"),
      description: new TranslatableMarkup("The entity type ID (e.g. node)."),
      required: TRUE,
    ),
    'template' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Template"),
      description: new TranslatableMarkup("The drafting template id to restrict the schema to."),
      required: FALSE,
    ),
  ],
)]
class GetContentSchema extends FunctionCallBase implements StructuredExecutableFunctionCallInterface {

  /**
   * The drafting schema provider.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface
   */
  protected DraftingSchemaProviderInterface $schemaProvider;

  /**
   * The structured output from execute().
   *
   * @var array
   */
  protected array $output = [];

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): FunctionCallInterface|static {
    $instance = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );
    $instance->schemaProvider = $container->get(DraftingSchemaProviderInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // getContextValues() returns raw values (NULL when not set), not
    // Context objects, so the arguments can be read directly.
    $values = $this->getContextValues();
    $bundle = (string) ($values['bundle'] ?? '');
    $entityTypeId = (string) ($values['entity_type_id'] ?? 'node');
    $templateId = (string) ($values['template'] ?? '');

    if (empty($bundle)) {
      $this->output = ['error' => 'Bundle is required.'];
      return;
    }

    try {
      $this->output = $this->schemaProvider->groups($entityTypeId, $bundle, $templateId);
    }
    catch (\Exception $e) {
      $this->output = ['error' => $e->getMessage()];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return json_encode($this->output, JSON_PRETTY_PRINT);
  }

  /**
   * {@inheritdoc}
   */
  public function getStructuredOutput(): array {
    return $this->output;
  }

}
