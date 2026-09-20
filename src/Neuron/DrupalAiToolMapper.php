<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Maps Neuron tools to drupal/ai tool definitions.
 */
final class DrupalAiToolMapper implements ToolMapperInterface {

  /**
   * {@inheritdoc}
   *
   * A tool wrapping a drupal/ai function call plugin keeps the definition
   * the plugin produced, so the model sees exactly what it declares.
   */
  public function map(array $tools): array {
    $functions = [];
    foreach ($tools as $tool) {
      if ($tool instanceof FunctionCallTool) {
        $functions[] = $tool->definition();
        continue;
      }
      if (!$tool instanceof ToolInterface) {
        continue;
      }
      $function = new ToolsFunctionInput($tool->getName(), []);
      $function->setDescription((string) $tool->getDescription());
      foreach ($tool->getProperties() as $property) {
        $input = new ToolsPropertyInput();
        $input->setFromArray(
          $property->getName(),
          $property->getJsonSchema() + ['required' => $property->isRequired()],
        );
        $function->setProperty($input);
      }
      $functions[] = $function;
    }
    return $functions;
  }

}
