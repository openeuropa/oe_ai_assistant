<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use NeuronAI\Providers\ToolMapperInterface;

/**
 * Maps Neuron tools to drupal/ai tool definitions.
 */
final class DrupalAiToolMapper implements ToolMapperInterface {

  /**
   * {@inheritdoc}
   */
  public function map(array $tools): array {
    $functions = [];
    foreach ($tools as $tool) {
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
