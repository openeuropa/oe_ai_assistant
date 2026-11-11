<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Property;

use Drupal\Core\Session\AccountInterface;
use Drupal\filter\FilterFormatRepositoryInterface;
use Drupal\schema_api\Attribute\PropertySchemaBuilder;
use Drupal\schema_api\Builder\PropertySchemaBuilderBase;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Lists the text formats the current user may write in.
 *
 * The list differs from one account to the next, so no field metadata can
 * state it. Matching the data type instead of the field type reaches every
 * field that stores formatted text, contrib ones included.
 */
#[PropertySchemaBuilder(
  data_type: 'filter_format',
  weight: 20,
)]
final class TextFormatBuilder extends PropertySchemaBuilderBase {

  public function __construct(
    private readonly FilterFormatRepositoryInterface $filterFormatRepository,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $schema['enum'] = array_keys(
      $this->filterFormatRepository->getFormatsForAccount($this->currentUser),
    );

    return $schema;
  }

}
