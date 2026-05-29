<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
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
  ],
)]
class GetContentSchema extends FunctionCallBase implements StructuredExecutableFunctionCallInterface {

  /**
   * The JSON Schema composer service.
   *
   * @var \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer
   */
  protected EntityJsonSchemaComposer $composer;

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
    $instance->composer = $container->get(EntityJsonSchemaComposer::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $bundle = '';
    $entityTypeId = 'node';
    foreach ($this->getContextValues() as $key => $context) {
      if ($key === 'bundle') {
        $bundle = (string) $context->getContextValue();
      }
      if ($key === 'entity_type_id') {
        $entityTypeId = (string) $context->getContextValue();
      }
    }

    if (empty($bundle)) {
      $this->output = ['error' => 'Bundle is required.'];
      return;
    }

    try {
      $this->output = $this->composer->splitSchemaIntoGroups(
        $entityTypeId, $bundle
      );
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
