<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Tool;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Combines Symfony AI tools with Drupal FunctionCall plugins.
 *
 * Wraps an inner ToolboxInterface (which handles plugin-defined
 * tools like draft_content) and transparently adds tools discovered
 * from Drupal's FunctionCallPluginManager. Tool execution is
 * dispatched to the correct handler based on whether the tool name
 * matches a registered FunctionCall plugin.
 */
class CompositeToolbox implements ToolboxInterface {

  /**
   * Cached merged tool list (inner + FunctionCall).
   *
   * @var \Symfony\AI\Platform\Tool\Tool[]|null
   */
  private ?array $cachedTools = NULL;

  /**
   * Constructs a CompositeToolbox.
   *
   * @param \Symfony\AI\Agent\Toolbox\ToolboxInterface $innerToolbox
   *   The Symfony AI toolbox for plugin-defined tools.
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   The Drupal FunctionCall plugin manager.
   * @param string|null $group
   *   The FunctionCall group to filter by, or NULL to skip
   *   FunctionCall plugin discovery.
   */
  public function __construct(
    private readonly ToolboxInterface $innerToolbox,
    private readonly FunctionCallPluginManager $functionCallManager,
    private readonly ?string $group = NULL,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Returns merged tools from the inner toolbox and any
   * FunctionCall plugins matching the configured group.
   */
  public function getTools(): array {
    if ($this->cachedTools !== NULL) {
      return $this->cachedTools;
    }

    $tools = $this->innerToolbox->getTools();

    if ($this->group !== NULL) {
      $definitions = $this->functionCallManager->getDefinitions();
      foreach ($definitions as $pluginId => $definition) {
        if (($definition['group'] ?? '') !== $this->group) {
          continue;
        }
        /** @var \Drupal\ai\Service\FunctionCalling\FunctionCallInterface $plugin */
        $plugin = $this->functionCallManager->createInstance($pluginId);
        $input = $plugin->normalize();
        $tools[] = new Tool(
          new ExecutionReference(self::class),
          $input->getName(),
          $input->getDescription(),
          $this->buildParameterSchema($input),
        );
      }
    }

    $this->cachedTools = $tools;
    return $tools;
  }

  /**
   * {@inheritdoc}
   *
   * Routes execution to the FunctionCall plugin system for
   * discovered tools, or to the inner toolbox for plugin-defined
   * tools (e.g. draft_content).
   */
  public function execute(ToolCall $toolCall): ToolResult {
    $name = $toolCall->getName();

    // Check if this tool is a Drupal FunctionCall plugin.
    if ($this->group !== NULL
      && $this->functionCallManager->functionExists($name)
    ) {
      return $this->executeFunctionCall($toolCall);
    }

    // Delegate to the inner Symfony AI toolbox.
    return $this->innerToolbox->execute($toolCall);
  }

  /**
   * Executes a Drupal FunctionCall plugin by tool call.
   *
   * Performs the standard FunctionCall lifecycle: normalize, populate
   * values, execute, and extract output.
   *
   * @param \Symfony\AI\Platform\Result\ToolCall $toolCall
   *   The tool call from the LLM.
   *
   * @return \Symfony\AI\Agent\Toolbox\ToolResult
   *   The execution result.
   */
  private function executeFunctionCall(ToolCall $toolCall): ToolResult {
    /** @var \Drupal\ai\Service\FunctionCalling\FunctionCallInterface $plugin */
    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName($toolCall->getName());
    $input = $plugin->normalize();
    $output = new ToolsFunctionOutput(
      $input, '', $toolCall->getArguments(),
    );
    $plugin->populateValues($output);

    // Execute the plugin if it supports execution.
    if ($plugin instanceof ExecutableFunctionCallInterface) {
      $plugin->execute();
    }

    // Extract structured or readable output.
    if ($plugin instanceof StructuredExecutableFunctionCallInterface) {
      $resultContent = Json::encode($plugin->getStructuredOutput());
    }
    elseif ($plugin instanceof ExecutableFunctionCallInterface) {
      $resultContent = $plugin->getReadableOutput();
    }
    else {
      $resultContent = 'executed';
    }

    return new ToolResult($toolCall, $resultContent);
  }

  /**
   * Converts a ToolsFunctionInput to a JSON Schema array.
   *
   * Reads the property definitions from the Drupal FunctionCall
   * plugin's normalized input and builds a JSON Schema compatible
   * with Symfony AI's Tool parameter format.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput $input
   *   The normalized function input.
   *
   * @return array<string, mixed>
   *   A JSON Schema object describing the tool's parameters.
   */
  private function buildParameterSchema(
    ToolsFunctionInput $input,
  ): array {
    $properties = [];
    $required = [];

    foreach ($input->getProperties() as $prop) {
      $schema = ['type' => $prop->getType()];
      $desc = $prop->getDescription();
      if (!empty($desc)) {
        $schema['description'] = $desc;
      }
      $properties[$prop->getName()] = $schema;

      if ($prop->getRequired()) {
        $required[] = $prop->getName();
      }
    }

    $schema = [
      'type' => 'object',
      'properties' => $properties,
    ];
    if (!empty($required)) {
      $schema['required'] = $required;
    }

    return $schema;
  }

}
