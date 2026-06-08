<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\oe_ai_assistant\Tool\CompositeToolbox;
use Drupal\oe_ai_assistant\Tool\CustomSchemaToolFactory;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Platform\Bridge\Mistral\PlatformFactory
  as MistralPlatformFactory;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory
  as OpenAiPlatformFactory;
use Symfony\AI\Platform\PlatformInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Creates configured Symfony AI Agent instances.
 *
 * Reads the default provider and model from Drupal's AI provider
 * config, creates the appropriate Symfony AI Platform, and wires
 * up the Toolbox, AgentProcessor, and any InputProcessors.
 *
 * The API key is resolved from Drupal's AI provider module config.
 */
class AgentFactory {

  /**
   * Constructs an AgentFactory.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The Drupal AI provider plugin manager for config resolution.
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   The FunctionCall plugin manager for tool discovery.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The Drupal config factory for reading provider settings.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The Key module repository for resolving API key values.
   */
  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
    private readonly FunctionCallPluginManager $functionCallManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Creates a Symfony AI Agent configured for chat streaming.
   *
   * @param array<object> $tools
   *   Tool objects to register (e.g. DraftContentTool instances).
   * @param array<\Symfony\AI\Platform\Tool\Tool> $toolMetadata
   *   Pre-built Tool metadata for tools that need custom JSON
   *   schemas (bypasses ReflectionToolFactory).
   * @param string|null $functionCallGroup
   *   FunctionCall plugin group to auto-discover, or NULL to skip.
   * @param \Psr\EventDispatcher\EventDispatcherInterface|null $eventDispatcher
   *   Event dispatcher for tool call lifecycle events.
   *
   * @return array{0: \Symfony\AI\Agent\AgentInterface, 1: string}
   *   A two-element array: the configured Agent and the model ID.
   */
  public function createAgent(
    array $tools = [],
    array $toolMetadata = [],
    ?string $functionCallGroup = NULL,
    ?EventDispatcherInterface $eventDispatcher = NULL,
  ): array {
    // Resolve provider and model from Drupal config.
    $defaults = $this->aiProvider
      ->getDefaultProviderForOperationType('chat');
    $providerId = $defaults['provider_id'];
    $modelId = $defaults['model_id'];

    // Create the Symfony AI Platform for the configured provider.
    $platform = $this->createPlatform($providerId);

    // Build the Symfony AI Toolbox with plugin-defined tools.
    $toolFactory = new CustomSchemaToolFactory(
      $toolMetadata, new ReflectionToolFactory(),
    );
    $innerToolbox = new Toolbox(
      $tools, $toolFactory, eventDispatcher: $eventDispatcher,
    );

    // Wrap with CompositeToolbox to add FunctionCall plugins.
    $compositeToolbox = new CompositeToolbox(
      $innerToolbox, $this->functionCallManager, $functionCallGroup,
    );

    // Build the processor pipeline.
    $agentProcessor = new AgentProcessor(
      $compositeToolbox,
      eventDispatcher: $eventDispatcher,
    );
    // Create and return the Agent.
    $agent = new Agent(
      $platform,
      $modelId,
      [$agentProcessor],
      [$agentProcessor],
    );

    return [$agent, $modelId];
  }

  /**
   * Creates a Symfony AI Platform for the given provider.
   *
   * @param string $providerId
   *   The Drupal AI provider plugin ID (e.g. 'mistral', 'openai').
   *
   * @return \Symfony\AI\Platform\PlatformInterface
   *   The configured platform.
   *
   * @throws \RuntimeException
   *   If the provider is unknown or the API key is missing.
   */
  private function createPlatform(string $providerId): PlatformInterface {
    $apiKey = $this->resolveApiKey($providerId);

    return match ($providerId) {
      'mistral' => MistralPlatformFactory::create($apiKey),
      'openai' => OpenAiPlatformFactory::create($apiKey),
      default => throw new \RuntimeException(
        "Unsupported AI provider for Symfony AI: '$providerId'. "
        . "Supported providers: mistral, openai.",
      ),
    };
  }

  /**
   * Resolves the API key for a given provider.
   *
   * Drupal's AI provider modules store API keys via the Key module.
   * The provider's settings config contains a Key entity ID in the
   * 'api_key' field, which we resolve to the actual key value.
   *
   * @param string $providerId
   *   The provider plugin ID.
   *
   * @return string
   *   The API key value.
   *
   * @throws \RuntimeException
   *   If no API key is configured or the Key entity is missing.
   */
  private function resolveApiKey(string $providerId): string {
    $configName = "ai_provider_{$providerId}.settings";
    $keyId = $this->configFactory->get($configName)->get('api_key');

    if (empty($keyId)) {
      throw new \RuntimeException(
        "No API key configured for AI provider '$providerId'. "
        . "Set it in the '$configName' configuration.",
      );
    }

    // Resolve the Key entity to get the actual key value.
    $key = $this->keyRepository->getKey($keyId);
    if ($key === NULL) {
      throw new \RuntimeException(
        "Key entity '$keyId' not found for provider '$providerId'.",
      );
    }

    $apiKey = $key->getKeyValue();
    if (empty($apiKey)) {
      throw new \RuntimeException(
        "Key entity '$keyId' has no value for provider '$providerId'.",
      );
    }

    return $apiKey;
  }

}
