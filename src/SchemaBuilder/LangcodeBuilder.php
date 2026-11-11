<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * Pins a draft to the default language of the site.
 *
 * Stating the language as a const rather than applying it after the answer
 * means the document a model returns is checked whole, with the language in
 * it, instead of being changed once nothing validates it any more.
 */
#[FieldSchemaBuilder(
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
