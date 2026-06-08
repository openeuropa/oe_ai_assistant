<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Builds an unsaved Drupal entity from an LLM-shaped fields map.
 *
 * Save-side counterpart to EntityJsonSchemaComposer: the composer produces
 * the JSON Schema that shapes the LLM's output; this builder turns that
 * output back into an unsaved Drupal entity ready for $entity->save().
 *
 * Encapsulates core's $serializer->deserialize() and the InlineEntityHydrator
 * workaround for Drupal core 11.3.x's inline-child-entity gap, so callers
 * only see one collaborator. See InlineEntityHydrator for the gap details.
 */
class DraftEntityBuilder {

  public function __construct(
    #[Autowire(service: 'serializer')]
    private readonly SerializerInterface $serializer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly InlineEntityHydrator $inlineEntityHydrator,
  ) {}

  /**
   * Builds an unsaved entity of the given type and bundle.
   *
   * @param string $entityTypeId
   *   The entity type ID to build (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   * @param array $fields
   *   The denormalize-input fields map, typically produced by the LLM.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The unsaved entity with inline child entities attached.
   *
   * @throws \InvalidArgumentException
   *   If the entity type does not exist or has no bundle key.
   * @throws \JsonException
   *   If $fields is not JSON-encodable.
   * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
   *   If core's deserialize rejects the payload.
   *
   * @todo Accept a per-build options parameter (owner, moderation state,
   *   template overrides) when templates or sub-agents need to move that
   *   post-build orchestration off the calling plugin.
   */
  public function fromLlmFields(string $entityTypeId, string $bundle, array $fields): ContentEntityInterface {
    try {
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    }
    catch (PluginNotFoundException $e) {
      throw new \InvalidArgumentException(
        sprintf('Unknown entity type "%s".', $entityTypeId),
        0,
        $e,
      );
    }

    $bundleKey = $entityType->getKey('bundle');
    if (!$bundleKey) {
      throw new \InvalidArgumentException(sprintf(
        'Entity type "%s" has no bundle key; DraftEntityBuilder requires a bundled content entity type.',
        $entityTypeId,
      ));
    }

    // Strip inline-entity fields out of the JSON BEFORE deserialize. Core
    // 11.3.x doesn't parse inline ERR child data but it still creates empty
    // placeholder items in the field, which would sit alongside the real
    // children we attach below. Pre-stripping keeps the field clean.
    [$nonInlineFields, $inlineEntityFields] = $this->inlineEntityHydrator
      ->splitInlineEntityFields($fields, $entityTypeId, $bundle);

    $json = json_encode(
      [$bundleKey => [['target_id' => $bundle]], ...$nonInlineFields],
      JSON_THROW_ON_ERROR,
    );

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->serializer->deserialize($json, $entityType->getClass(), 'json');

    foreach ($inlineEntityFields as $fieldName => $info) {
      $children = $this->inlineEntityHydrator->buildInlineEntities(
        $info['items'],
        $info['target_type'],
      );
      foreach ($children as $child) {
        $entity->get($fieldName)->appendItem($child);
      }
    }

    return $entity;
  }

}
