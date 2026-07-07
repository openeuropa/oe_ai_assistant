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
use Psr\Log\LoggerInterface;
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
   * The module logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $moduleLogger;

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
    $instance->moduleLogger = $container->get('logger.channel.oe_ai_assistant');
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
    // The context is string-typed; treat an unset or empty value as NULL so
    // the provider auto-selects a template.
    $templateId = ($values['template'] ?? '') !== '' ? (string) $values['template'] : NULL;

    if (empty($bundle)) {
      $this->output = ['error' => 'Bundle is required.'];
      return;
    }

    try {
      $this->output = $this->schemaProvider->groups($entityTypeId, $bundle, $templateId);
    }
    catch (\InvalidArgumentException $e) {
      // Invalid input (e.g. a bad template id): report it to the LLM.
      $this->output = ['error' => $e->getMessage()];
    }
    catch (\Exception $e) {
      // Log the detail, return a generic message to the LLM.
      $this->moduleLogger->error('get_content_schema failed: @message', ['@message' => $e->getMessage()]);
      $this->output = ['error' => 'The content schema could not be loaded.'];
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
