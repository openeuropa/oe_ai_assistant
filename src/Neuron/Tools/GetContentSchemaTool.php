<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use NeuronAI\Tools\Tool;
use Psr\Log\LoggerInterface;

/**
 * Tool returning the field groups of the content type being drafted.
 *
 * The entity type, bundle and template are pinned by the caller, so the
 * model cannot read the schema of anything but the content it serves.
 */
final class GetContentSchemaTool extends Tool {

  public const NAME = 'get_content_schema';

  /**
   * GetContentSchemaTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface $schemaProvider
   *   The drafting schema provider.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param string $entityTypeId
   *   The entity type id of the content being drafted.
   * @param string $bundle
   *   The bundle of the content being drafted.
   * @param string|null $templateId
   *   The drafting template restricting the schema, or NULL for the latest
   *   template of the bundle.
   */
  public function __construct(
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    private readonly LoggerInterface $logger,
    private readonly string $entityTypeId,
    private readonly string $bundle,
    private readonly ?string $templateId,
  ) {
    parent::__construct(
      self::NAME,
      'Returns the content type schema split into field groups.'
      . ' Call this to discover what fields need to be drafted'
      . ' and what information is needed from the user.',
    );
  }

  /**
   * Returns the field groups as JSON, or an error payload the model can read.
   */
  public function __invoke(): string {
    try {
      $output = $this->schemaProvider->groups($this->entityTypeId, $this->bundle, $this->templateId);
    }
    catch (\InvalidArgumentException $e) {
      $output = ['error' => $e->getMessage()];
    }
    catch (\Exception $e) {
      $this->logger->error('get_content_schema failed: @message', ['@message' => $e->getMessage()]);
      $output = ['error' => 'The content schema could not be loaded.'];
    }
    return json_encode($output);
  }

}
