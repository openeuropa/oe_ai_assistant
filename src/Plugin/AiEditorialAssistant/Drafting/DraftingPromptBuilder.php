<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\Component\Serialization\Json;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Psr\Log\LoggerInterface;

/**
 * Composes content type schema text and field index for drafting.
 *
 * The system prompt and tool definitions now live on the ai_agents
 * config entity (oe_drafting_router). This class handles only the
 * per-request schema composition that gets appended to the agent's
 * base system prompt at runtime.
 *
 * This class is not a Drupal service; it is instantiated directly
 * by DraftingPlugin.
 */
class DraftingPromptBuilder {

  /**
   * Per-request schema cache keyed by "entityTypeId:bundle".
   *
   * @var array<string, array>
   */
  private array $cachedSchema = [];

  /**
   * Constructs a DraftingPromptBuilder.
   *
   * @param \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer $composer
   *   The JSON Schema composer service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel for oe_ai_assistant.
   */
  public function __construct(
    private readonly EntityJsonSchemaComposer $composer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the content type schema as a JSON string.
   *
   * This text is appended to the agent config entity's system prompt
   * so the LLM knows which fields and value shapes to produce.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   *
   * @return string
   *   The schema as "Content type schema:\n{json}", or empty string
   *   if the bundle is empty or composition fails.
   */
  public function buildSchemaText(string $entityTypeId, string $bundle): string {
    if (empty($bundle)) {
      return '';
    }
    try {
      $schema = $this->getSchema($entityTypeId, $bundle);
      return "\n\nContent type schema:\n" . Json::encode($schema);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not load schema for @type/@bundle: @error', [
        '@type' => $entityTypeId,
        '@bundle' => $bundle,
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Builds a flat field index from the content type schema.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "article").
   *
   * @return array<string, array>
   *   Field schemas keyed by machine name, or empty array on failure.
   */
  public function buildFieldIndex(string $entityTypeId, string $bundle): array {
    if (empty($bundle)) {
      return [];
    }
    try {
      $schema = $this->getSchema($entityTypeId, $bundle);
      return $schema['properties'] ?? [];
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Returns the composed schema, using a per-request cache.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return array<string, mixed>
   *   The composed schema array.
   */
  private function getSchema(string $entityTypeId, string $bundle): array {
    $cacheKey = "$entityTypeId:$bundle";
    if (!isset($this->cachedSchema[$cacheKey])) {
      $this->cachedSchema[$cacheKey] = $this->composer->compose(
        $entityTypeId,
        $bundle,
      );
    }
    return $this->cachedSchema[$cacheKey];
  }

}
