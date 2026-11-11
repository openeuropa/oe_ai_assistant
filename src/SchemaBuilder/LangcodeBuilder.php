<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\schema_builder\Attribute\FieldSchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\FieldSchemaBuilderBase;
use Drupal\schema_builder\Context\FieldContext;

/**
 * Pins a draft to the default language of the site.
 *
 * The language is a const in the schema rather than something set on the
 * answer afterwards, so it's there while the document is validated.
 */
#[FieldSchemaBuilder(
  entity_type: 'node',
  field_name: 'langcode',
  weight: 30,
)]
final class LangcodeBuilder extends FieldSchemaBuilderBase {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    $schema['items'] = [
      'type' => 'object',
      'properties' => [
        'value' => [
          'type' => 'string',
          'const' => $this->languageManager->getDefaultLanguage()->getId(),
        ],
      ],
      'additionalProperties' => FALSE,
      'required' => ['value'],
    ];
    $schema['maxItems'] = 1;

    return $schema;
  }

}
