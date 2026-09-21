<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Service\StructuredOutputValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for validating model output against its own schema.
 */
#[Group('oe_ai_assistant')]
class StructuredOutputValidatorTest extends TestCase {

  /**
   * The schema a drafted "main fields" group has to satisfy.
   */
  private const SCHEMA = [
    'type' => 'object',
    'properties' => [
      'title' => [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'properties' => ['value' => ['type' => 'string']],
          'required' => ['value'],
        ],
      ],
    ],
    'required' => ['title'],
    'additionalProperties' => FALSE,
  ];

  /**
   * Tests that output matching the schema reports nothing.
   */
  public function testValidOutputHasNoErrors(): void {
    $errors = (new StructuredOutputValidator())->validate(
      ['title' => [['value' => 'A headline']]],
      self::SCHEMA,
    );

    $this->assertSame([], $errors);
  }

  /**
   * Tests that a wrong type is reported with its path in schema terms.
   */
  public function testWrongTypeNamesThePathAndTheExpectedType(): void {
    $errors = (new StructuredOutputValidator())->validate(
      ['title' => 'A headline'],
      self::SCHEMA,
    );

    $this->assertCount(1, $errors);
    $this->assertStringStartsWith('title: ', $errors[0]);
    $this->assertStringContainsString('array', $errors[0]);
  }

  /**
   * Tests that a missing property is reported against the object itself.
   */
  public function testMissingRequiredPropertyIsReported(): void {
    $errors = (new StructuredOutputValidator())->validate([], self::SCHEMA);

    $this->assertCount(1, $errors);
    $this->assertStringContainsString('title', $errors[0]);
    $this->assertStringContainsString('required', $errors[0]);
  }

  /**
   * Tests that a nested value reports the full path to the offending item.
   */
  public function testNestedErrorCarriesItsItemIndex(): void {
    $errors = (new StructuredOutputValidator())->validate(
      ['title' => [['value' => 'Fine'], ['value' => 42]]],
      self::SCHEMA,
    );

    $this->assertCount(1, $errors);
    $this->assertStringStartsWith('title[1].value: ', $errors[0]);
  }

  /**
   * Tests that a property outside the schema is reported, not ignored.
   */
  public function testUnknownPropertyIsReported(): void {
    $errors = (new StructuredOutputValidator())->validate(
      ['title' => [['value' => 'A headline']], 'field_invented' => []],
      self::SCHEMA,
    );

    $this->assertNotSame([], $errors);
    $this->assertStringContainsString('field_invented', implode(' ', $errors));
  }

  /**
   * Tests that the same problem reported by every variant is stated once.
   *
   * A paragraph field offers one schema variant per bundle, so a missing
   * discriminator fails all of them with the identical message.
   */
  public function testRepeatedVariantErrorsAreStatedOnce(): void {
    $schema = [
      'type' => 'object',
      'properties' => [
        'field_content_paragraphs' => [
          'type' => 'array',
          'items' => [
            'oneOf' => [
              [
                'type' => 'object',
                'properties' => ['type' => ['type' => 'array']],
                'required' => ['type'],
              ],
              [
                'type' => 'object',
                'properties' => ['type' => ['type' => 'array']],
                'required' => ['type'],
              ],
            ],
          ],
        ],
      ],
    ];

    $errors = (new StructuredOutputValidator())->validate(
      ['field_content_paragraphs' => [['field_text_body' => []]]],
      $schema,
    );

    $this->assertSame(
      $errors,
      array_values(array_unique($errors)),
      'The same problem is not repeated once per rejected variant.',
    );
  }

}
