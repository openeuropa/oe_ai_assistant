<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Context;

/**
 * Says which field an item belongs to, and what Drupal asks of the item.
 *
 * The constraints here are the ones the item definition carries, such as the
 * AllowedValues that names the bundles a reference may point at. They are
 * not the ones the field itself carries.
 */
final class ItemContext extends FieldContext {}
