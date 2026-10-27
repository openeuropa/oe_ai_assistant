<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\Tests\oe_ai_assistant\Traits\TextFormatUserTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the DraftAssembler service against an existing node.
 *
 * Module list aligns with DraftEntityBuilderTest so the shared
 * `oe_ai_assistant_test` fixture (oe_news) is available.
 */
#[Group('oe_ai_assistant')]
class DraftAssemblerTest extends KernelTestBase {

  use TextFormatUserTrait;

  /**
   * The permissions a news editor has besides text formats.
   */
  private const EDITOR_PERMISSIONS = [
    'access content',
    'edit any oe_news content',
    // Content moderation denies update access without a usable transition.
    'use editorial transition create_new_draft',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'datetime',
    'entity_reference_revisions',
    'paragraphs',
    'file',
    'image',
    'link',
    'taxonomy',
    'inline_entity_form',
    'content_moderation',
    'workflows',
    'options',
    'key',
    'ai',
    'oe_ai_assistant',
    'state_machine',
    'document_loader',
    'document_loader_tika',
    'oe_ai_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'field',
      'filter',
      'node',
      'oe_ai_assistant_test',
    ]);
  }

  /**
   * Keeps the existing item's text format when the payload omits it.
   */
  public function testUpdatePreservesExistingTextFormat(): void {
    // The user's default format (lowest weight) differs from the one stored
    // on the node, so a resolver that ignores the existing item shows up as a
    // format change.
    $this->createBasicAndRichFormats();

    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);
    $node = $this->createNewsWithBodyFormat('oe_test_rich');

    $assembled = $this->assembleBody(['value' => '<p>Rewritten body.</p>'], $node);

    $this->assertSame('<p>Rewritten body.</p>', $assembled->get('field_body')->value);
    $this->assertSame('oe_test_rich', $assembled->get('field_body')->format);
  }

  /**
   * Replaces a stored format the user may not use when the payload omits it.
   */
  public function testUpdateReplacesStoredFormatTheUserMayNotUse(): void {
    $this->createBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic'], self::EDITOR_PERMISSIONS);
    $node = $this->createNewsWithBodyFormat('oe_test_rich');

    $assembled = $this->assembleBody(['value' => '<p>Rewritten body.</p>'], $node);

    $this->assertSame('<p>Rewritten body.</p>', $assembled->get('field_body')->value);
    $this->assertSame('oe_test_basic', $assembled->get('field_body')->format);
  }

  /**
   * Keeps the stored format when the payload's format is not allowed.
   */
  public function testUpdateKeepsStoredFormatOverRejectedSuppliedFormat(): void {
    $this->createBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);
    $node = $this->createNewsWithBodyFormat('oe_test_rich');

    $assembled = $this->assembleBody(['value' => '<p>Rewritten body.</p>', 'format' => 'full_html'], $node);

    // The user may not use full_html, so the stored format stands in rather
    // than the lightest one.
    $this->assertSame('oe_test_rich', $assembled->get('field_body')->format);
  }

  /**
   * Creates a light basic format and a heavier rich one.
   */
  private function createBasicAndRichFormats(): void {
    FilterFormat::create(['format' => 'oe_test_basic', 'name' => 'Basic', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_rich', 'name' => 'Rich', 'weight' => 0])->save();
  }

  /**
   * Assembles an update of a news node's body with the given item.
   *
   * @param array $bodyItem
   *   The body item, as the LLM sends it.
   * @param \Drupal\node\Entity\Node $node
   *   The stored node.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The updated node.
   */
  private function assembleBody(array $bodyItem, Node $node): ContentEntityInterface {
    return $this->container->get(DraftAssemblerInterface::class)->assemble('oe_news', ['field_body' => [$bodyItem]], NULL, $node);
  }

  /**
   * Creates and saves a news node whose body has the given format.
   */
  private function createNewsWithBodyFormat(string $format): Node {
    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Existing news',
      'field_news_type' => 'announcement',
      'field_body' => ['value' => '<p>Original body.</p>', 'format' => $format],
    ]);
    $node->save();
    return $node;
  }

}
