<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * Pins the bundle field of an entity to the bundle being described.
 *
 * A reference that offers a choice of bundles needs each choice to name
 * itself, or a reader cannot tell which one a payload is meant to be, and
 * two bundles sharing a field would both match.
 */
#[FieldSchemaBuilder(
  weight: 20,
)]
final class BundleKeyBuilder extends FieldSchemaBuilderBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    $bundleKey = $this->entityTypeManager
      ->getDefinition($context->entityTypeId)
      ->getKey('bundle');

    if ($bundleKey !== $context->fieldDefinition->getName()) {
      return $schema;
    }

    $schema['items']['properties']['target_id']['const'] = $context->bundle;
    $schema['maxItems'] = 1;

    return $schema;
  }

}
