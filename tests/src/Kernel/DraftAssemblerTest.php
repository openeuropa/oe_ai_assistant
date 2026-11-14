<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\Tests\oe_ai_assistant\Traits\TextFormatUserTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

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
   * What an editor of oe_news needs on top of text format permissions.
   */
  private const EDITOR_PERMISSIONS = [
    'access content',
    'create oe_news content',
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
    'ai_agents',
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
   * Replaces a stored format that no longer exists or is disabled.
   */
  #[TestWith(['html'])]
  #[TestWith(['oe_test_disabled'])]
  public function testUpdateReplacesInvalidStoredFormat(string $format): void {
    $this->setUpBasicAndRichFormats();
    FilterFormat::create(['format' => 'oe_test_disabled', 'name' => 'Disabled', 'weight' => -20, 'status' => FALSE])->save();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich', 'oe_test_disabled'], self::EDITOR_PERMISSIONS);
    $node = $this->createNewsWithBodyFormat($format);

    $assembled = $this->assembler()->assemble(
      'oe_news',
      ['field_body' => [['value' => '<p>Rewritten body.</p>']]],
      NULL,
      $node,
    );

    $this->assertSame('oe_test_basic', $assembled->get('field_body')->format);
  }

  /**
   * Replaces a supplied format the user may not use.
   */
  public function testReplacesSuppliedFormatTheUserMayNotUse(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic'], self::EDITOR_PERMISSIONS);

    // The body field allows any format, and the rich one has no filters, so
    // keeping it would store the LLM's markup unfiltered.
    $assembled = $this->assembler()->assemble('oe_news', [
      'title' => [['value' => 'New news']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>LLM body.</p>', 'format' => 'oe_test_rich']],
    ], NULL);

    $this->assertSame('oe_test_basic', $assembled->get('field_body')->format);
  }

  /**
   * Keeps a template default's format over the user's default format.
   *
   * A template refuses on save a default whose format is not valid.
   */
  public function testKeepsTemplateDefaultFormat(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);
    $template = AiDraftingTemplate::load('news_default');
    $template->set('defaults', $template->get('defaults') + [
      'field_body' => ['default_value' => [['value' => '<p>Template body.</p>', 'format' => 'oe_test_rich']]],
    ]);
    $template->save();

    $assembled = $this->assembler()->assemble('oe_news', [
      'title' => [['value' => 'New news']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>LLM body.</p>', 'format' => 'oe_test_basic']],
    ], 'news_default');

    $this->assertSame('<p>Template body.</p>', $assembled->get('field_body')->value);
    $this->assertSame('oe_test_rich', $assembled->get('field_body')->format);
  }

  /**
   * Keeps a template default's format over the stored format on update.
   */
  public function testUpdateKeepsTemplateDefaultFormat(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);
    $template = AiDraftingTemplate::load('news_default');
    $template->set('defaults', $template->get('defaults') + [
      'field_body' => ['default_value' => [['value' => '<p>Template body.</p>', 'format' => 'oe_test_rich']]],
    ]);
    $template->save();
    // The stored format is valid as well, so only precedence decides.
    $node = $this->createNewsWithBodyFormat('oe_test_basic');

    $assembled = $this->assembler()->assemble(
      'oe_news',
      ['field_body' => [['value' => '<p>LLM body.</p>']]],
      'news_default',
      $node,
    );

    // The template's value wins the collision, and its format comes with it.
    $this->assertSame('<p>Template body.</p>', $assembled->get('field_body')->value);
    $this->assertSame('oe_test_rich', $assembled->get('field_body')->format);
  }

  /**
   * Tests that an update keeps the stored format over a supplied one.
   *
   * A format the LLM sends must not replace the format the node already
   * stores, even when the user may use it.
   */
  public function testUpdateKeepsStoredFormatOverSuppliedFormat(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);
    $node = $this->createNewsWithBodyFormat('oe_test_rich');

    $assembled = $this->assembler()->assemble(
      'oe_news',
      ['field_body' => [['value' => '<p>Rewritten body.</p>', 'format' => 'oe_test_basic']]],
      NULL,
      $node,
    );

    // The user may use both formats, so the stored one outranks the LLM's.
    $this->assertSame('oe_test_rich', $assembled->get('field_body')->format);
  }

  /**
   * Tests that a supplied format the user may use is ignored.
   *
   * The LLM does not choose the text format: a format it sends is dropped
   * and the field's default applies, even when the user may use it.
   */
  public function testIgnoresSuppliedFormatTheUserMayUse(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);

    $assembled = $this->assembler()->assemble('oe_news', [
      'title' => [['value' => 'New news']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>LLM body.</p>', 'format' => 'oe_test_rich']],
    ], NULL);

    // The LLM does not choose the format, so the lightest one the user may
    // use applies even though the rich one is usable.
    $this->assertSame('oe_test_basic', $assembled->get('field_body')->format);
  }

  /**
   * Tests that a supplied format is ignored when no allowed one is usable.
   *
   * When the user may use none of the field's formats, the resolver falls
   * back to the field's formats; the LLM must not pick among them.
   */
  public function testIgnoresSuppliedFormatWhenUserMayUseNoAllowedFormat(): void {
    $this->setUpBasicAndRichFormats();
    FilterFormat::create(['format' => 'oe_test_other', 'name' => 'Other', 'weight' => 10])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['oe_test_basic', 'oe_test_rich']);
    $this->setUserWithFormats(['oe_test_other'], self::EDITOR_PERMISSIONS);

    $assembled = $this->assembler()->assemble('oe_news', [
      'title' => [['value' => 'New news']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>LLM body.</p>', 'format' => 'oe_test_rich']],
    ], NULL);

    // The user may use none of the allowed formats, so the field's own
    // formats apply and the lightest one wins over the LLM's choice.
    $this->assertSame('oe_test_basic', $assembled->get('field_body')->format);
  }

  /**
   * Tests that a supplied format on an inline paragraph is ignored.
   *
   * Paragraph text is resolved on its own entity, so a format the LLM sends
   * there must be dropped as well.
   */
  public function testIgnoresSuppliedFormatOnInlineParagraph(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic', 'oe_test_rich'], self::EDITOR_PERMISSIONS);

    $assembled = $this->assembler()->assemble('oe_news', [
      'title' => [['value' => 'With paragraphs']],
      'field_news_type' => [['value' => 'announcement']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => '<p>Inline body</p>', 'format' => 'oe_test_rich']],
        ],
      ],
    ], NULL);

    // The paragraph text gets the lightest usable format, not the LLM's.
    $paragraph = $assembled->get('field_content_paragraphs')->get(0)->entity;
    $this->assertSame('oe_test_basic', $paragraph->get('field_text_body')->format);
  }

  /**
   * Tests that an update moves an unusable stored format to a usable one.
   *
   * The drafted text is written with a format the editor may use, replacing
   * the stored one.
   */
  public function testUpdateSwitchesUnusableStoredFormatToUsableOne(): void {
    $this->setUpBasicAndRichFormats();
    $this->setUserWithFormats(['oe_test_basic'], self::EDITOR_PERMISSIONS);
    $node = $this->createNewsWithBodyFormat('oe_test_rich');

    $assembled = $this->assembler()->assemble(
      'oe_news',
      ['field_body' => [['value' => '<p>Rewritten body.</p>']]],
      NULL,
      $node,
    );

    // The drafted text is kept, with the only format the editor may use.
    $this->assertSame('<p>Rewritten body.</p>', $assembled->get('field_body')->value);
    $this->assertSame('oe_test_basic', $assembled->get('field_body')->format);
  }

  /**
   * Returns the assembler service from the container.
   */
  private function assembler(): DraftAssemblerInterface {
    return $this->container->get(DraftAssemblerInterface::class);
  }

  /**
   * Creates a basic format, lightest, and a rich one.
   */
  private function setUpBasicAndRichFormats(): void {
    FilterFormat::create(['format' => 'oe_test_basic', 'name' => 'Basic', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_rich', 'name' => 'Rich', 'weight' => 0])->save();
  }

  /**
   * Saves an oe_news node whose body is stored in the given format.
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

  /**
   * Restricts a field to the given formats.
   */
  private function setAllowedFormats(string $entityTypeId, string $bundle, string $fieldName, array $formats): void {
    $fieldConfig = FieldConfig::loadByName($entityTypeId, $bundle, $fieldName);
    $fieldConfig->setSetting('allowed_formats', $formats);
    $fieldConfig->save();
  }

}
