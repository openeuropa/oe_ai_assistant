<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use NeuronAI\Providers\ToolMapperInterface;

/**
 * Maps Neuron tools to drupal/ai tool definitions.
 */
final class ToolMapper implements ToolMapperInterface {

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
        $input->setFromArray($property->getName(), $property->getJsonSchema());
        // The flag is not a schema key: passed in the array it would be
        // rendered into the property, which providers reject.
        $input->setRequired($property->isRequired());
        $function->setProperty($input);
      }
      $functions[] = $function;
    }
    return $functions;
  }

}
