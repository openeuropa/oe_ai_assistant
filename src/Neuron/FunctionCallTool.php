<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Psr\Log\LoggerInterface;

/**
 * Neuron tool that executes a drupal/ai function call plugin.
 */
final class FunctionCallTool extends Tool {

  /**
   * FunctionCallTool constructor.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput $definition
   *   The plugin's normalized definition, with the fixed properties removed.
   * @param array $fixedContexts
   *   Context values forced at execution time, keyed by context name. They
   *   override whatever the model supplied.
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   The function call plugin manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly ToolsFunctionInput $definition,
    private readonly array $fixedContexts,
    private readonly FunctionCallPluginManager $functionCallManager,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($definition->getName(), $definition->getDescription());
  }

  /**
   * Returns the definition sent to the model.
   */
  public function definition(): ToolsFunctionInput {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    $properties = [];
    foreach ($this->definition->getProperties() as $property) {
      $schema = $property->renderPropertyArray();
      try {
        $type = PropertyType::fromSchema($schema['type']);
      }
      catch (\Throwable) {
        $type = PropertyType::STRING;
      }
      $properties[] = new ToolProperty(
        $property->getName(),
        $type,
        $property->getDescription() !== '' ? $property->getDescription() : NULL,
        $property->isRequired(),
        $schema['enum'] ?? [],
      );
    }
    return $properties;
  }

  /**
   * Executes the plugin with the model's arguments and the fixed contexts.
   *
   * A failure is reported to the model as an error payload, so the
   * conversation continues instead of aborting the request.
   */
  public function __invoke(mixed ...$inputs): string {
    $output = new ToolsFunctionOutput(NULL, (string) $this->getCallId(), $this->getInputs());
    $output->setName($this->getName());
    try {
      $plugin = $this->functionCallManager->convertToolResponseToObject($output);
      foreach ($this->fixedContexts as $name => $value) {
        $plugin->setContextValue($name, $value);
      }
      if ($plugin instanceof ExecutableFunctionCallInterface) {
        $plugin->execute();
      }
      if ($plugin instanceof StructuredExecutableFunctionCallInterface) {
        return json_encode($plugin->getStructuredOutput());
      }
      return $plugin->getReadableOutput();
    }
    catch (\Throwable $e) {
      $this->logger->error('Tool @name failed: @error', [
        '@name' => $this->getName(),
        '@error' => $e->getMessage(),
      ]);
      return json_encode(['error' => $e->getMessage()]);
    }
  }

}
