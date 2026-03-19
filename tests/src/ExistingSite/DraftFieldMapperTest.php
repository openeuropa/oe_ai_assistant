<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the DraftFieldMapper service.
 */
class DraftFieldMapperTest extends ExistingSiteBase {

  /**
   * The field mapper service.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftFieldMapper
   */
  protected $fieldMapper;

  /**
   * The IDs of existing entities before the test, keyed by entity type.
   *
   * @var array
   */
  protected $existingEntityIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->trackEntityType('node');
    $this->trackEntityType('paragraph');
    $this->fieldMapper = \Drupal::service(
      'Drupal\oe_ai_assistant\Service\DraftFieldMapper'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $this->deleteTestEntities();
    parent::tearDown();
  }

  /**
   * Records existing entity IDs so test-created entities can be cleaned up.
   */
  protected function trackEntityType(string $entityType): void {
    $this->existingEntityIds[$entityType] = \Drupal::entityTypeManager()
      ->getStorage($entityType)
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Deletes entities created during the test.
   */
  protected function deleteTestEntities(): void {
    foreach ($this->existingEntityIds as $entityType => $previousIds) {
      $storage = \Drupal::entityTypeManager()->getStorage($entityType);
      $currentIds = $storage->getQuery()->accessCheck(FALSE)->execute();
      $newIds = array_diff($currentIds, $previousIds);
      if ($newIds) {
        $storage->delete($storage->loadMultiple($newIds));
      }
    }
  }

  /**
   * Tests mapping simple text fields to a node.
   */
  public function testMapSimpleTextFields(): void {
    $fields = [
      'title' => 'Test Draft Title',
      'field_teaser' => 'Short',
    ];

    $node = $this->fieldMapper->createNode('oe_news', $fields);

    $this->assertEquals('Test Draft Title', $node->getTitle());
    $this->assertEquals('Short', $node->get('field_teaser')->value);
  }

  /**
   * Tests mapping formatted text fields.
   */
  public function testMapFormattedTextField(): void {
    $fields = [
      'title' => 'Test',
      'body' => [
        'value' => '<p>Test body</p>',
      ],
    ];

    $node = $this->fieldMapper->createNode('oe_news', $fields);

    $this->assertEquals('<p>Test body</p>', $node->get('body')->value);
    $this->assertNotEmpty($node->get('body')->format);
  }

  /**
   * Tests that entity reference fields are skipped.
   */
  public function testSkipsEntityReferenceFields(): void {
    $fields = [
      'title' => 'Test',
      'field_contacts' => ['target_id' => 1],
    ];

    $node = $this->fieldMapper->createNode('oe_news', $fields);

    $this->assertTrue($node->get('field_contacts')->isEmpty());
  }

  /**
   * Tests that the node is saved with unpublished moderation state.
   */
  public function testNodeSavedAsUnpublished(): void {
    $fields = [
      'title' => 'Draft Node',
    ];

    $node = $this->fieldMapper->createNode('oe_news', $fields);

    $this->assertFalse($node->isPublished());
    $this->assertEquals('draft', $node->get('moderation_state')->value);
  }

  /**
   * Tests mapping with an invalid bundle.
   */
  public function testInvalidBundleThrowsException(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->fieldMapper->createNode('nonexistent_bundle', ['title' => 'x']);
  }

}
