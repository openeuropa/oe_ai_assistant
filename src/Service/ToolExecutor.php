<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Executes FunctionCall plugins via the plugin manager.
 *
 * This is the production implementation of ToolExecutorInterface.
 * It delegates to FunctionCallPluginManager to look up, populate,
 * and execute tool call plugins.
 */
class ToolExecutor implements ToolExecutorInterface {

  /**
   * Constructs a ToolExecutor.
   *
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   The FunctionCall plugin manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    #[Autowire(service: 'plugin.manager.ai.function_calls')]
    private readonly FunctionCallPluginManager $functionCallManager,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function execute($toolCall): string {
    try {
      $plugin = $this->functionCallManager
        ->convertToolResponseToObject($toolCall);
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
        '@name' => $toolCall->getName(),
        '@error' => $e->getMessage(),
      ]);
      return json_encode(['error' => $e->getMessage()]);
    }
  }

}
