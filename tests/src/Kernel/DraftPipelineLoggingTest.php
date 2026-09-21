<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\oe_ai_assistant\Service\InlineEntityHydrator;
use Drupal\oe_ai_assistant\Service\TemplateDefaultsResolverInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what the draft assembly pipeline records when it fails.
 */
#[Group('oe_ai_assistant')]
class DraftPipelineLoggingTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime',
    'field',
    'file',
    'filter',
    'node',
    'options',
    'system',
    'text',
    'user',
    'workflows',
    'content_moderation',
    'serialization',
    'image',
    'link',
    'taxonomy',
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    'oe_ai_assistant',
    'state_machine',
    'document_loader',
    'document_loader_tika',
    'oe_ai_assistant_test',
  ];

  /**
   * Captures everything the module logs during a test.
   */
  private TestLogger $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    // Deleting a node touches the grants table, which is not an entity
    // schema and so is not installed with the node entity type.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'oe_ai_assistant_test']);

    $this->logger = new TestLogger();
    $this->container->get('logger.factory')->addLogger($this->logger);

    // User 1 passes every permission check, so the tests exercise the
    // pipeline rather than access control.
    $this->setUpCurrentUser(['uid' => 1]);
  }

  /**
   * Tests that a default pointing at a deleted entity names its field.
   */
  public function testUnresolvableTargetUuidNamesItsField(): void {
    $contact = $this->createContactNode();
    $uuid = $contact->uuid();
    $contact->delete();

    try {
      $this->container->get(TemplateDefaultsResolverInterface::class)->resolve([
        'field_contacts' => [
          'default_value' => [['target_uuid' => $uuid]],
        ],
      ], 'node', 'oe_news');
      $this->fail('Expected an unresolvable uuid to throw.');
    }
    catch (\RuntimeException) {
      // The log is what this test is about, asserted below.
    }

    $record = $this->findRecord(RfcLogLevel::WARNING, 'references a uuid that no entity carries');
    $this->assertNotNull($record, 'An unresolvable default target is logged as a warning.');
    $this->assertSame('field_contacts', $record['context']['@field']);
    $this->assertSame('node', $record['context']['@type']);
    $this->assertSame('oe_news', $record['context']['@bundle']);
    $this->assertSame($uuid, $record['context']['@uuids']);
  }

  /**
   * Tests that a bad template default fails as its own error code.
   */
  public function testDefaultsFailureIsReportedSeparately(): void {
    $contact = $this->createContactNode();
    $template = AiDraftingTemplate::load('news_default');
    $defaults = $template->get('defaults');
    $defaults['field_contacts'] = [
      'default_value' => [['target_uuid' => $contact->uuid()]],
    ];
    $template->set('defaults', $defaults)->save();
    $contact->delete();

    try {
      $this->assembler()->assemble('oe_news', [
        'title' => [['value' => 'Draft with a dangling default']],
      ], 'news_default');
      $this->fail('Expected the dangling default to fail assembly.');
    }
    catch (ActionException $e) {
      $this->assertSame('defaults_failed', $e->errorCode);
      $this->assertSame(500, $e->statusCode);
    }

    $this->assertNotNull(
      $this->findRecord(RfcLogLevel::ERROR, 'could not be applied to node bundle'),
      'The defaults stage reports its own failure, not a generic payload error.',
    );
  }

  /**
   * Tests that a paragraph without a bundle is logged with its position.
   */
  public function testInlineEntityWithoutBundleLogsItsPath(): void {
    try {
      $this->hydrator()->buildInlineEntities(
        [['field_text_body' => [['value' => 'Orphan text.']]]],
        'paragraph',
        'field_content_paragraphs',
      );
      $this->fail('Expected a bundle-less inline entity to throw.');
    }
    catch (\InvalidArgumentException) {
      // The log is what this test is about, asserted below.
    }

    $record = $this->findRecord(RfcLogLevel::ERROR, 'names no bundle');
    $this->assertNotNull($record);
    $this->assertSame('field_content_paragraphs[0]', $record['context']['@path']);
    $this->assertSame('field_text_body', $record['context']['@fields']);
  }

  /**
   * Tests that a failure inside a nested paragraph reports the full path.
   */
  public function testNestedInlineEntityFailureLogsNestingPath(): void {
    $items = [
      [
        'type' => [['target_id' => 'section']],
        'field_section_paragraphs' => [
          ['type' => [['target_id' => 'text_block']]],
          ['field_text_body' => [['value' => 'No bundle here.']]],
        ],
      ],
    ];

    try {
      $this->hydrator()->buildInlineEntities($items, 'paragraph', 'field_content_paragraphs');
      $this->fail('Expected the nested bundle-less item to throw.');
    }
    catch (\InvalidArgumentException) {
      // The log is what this test is about, asserted below.
    }

    $record = $this->findRecord(RfcLogLevel::ERROR, 'names no bundle');
    $this->assertNotNull($record);
    $this->assertSame(
      'field_content_paragraphs[0].field_section_paragraphs[1]',
      $record['context']['@path'],
    );

    // The parent item reports the failure too, so the trail reads from the
    // broken child up to the field it hangs from.
    $parent = $this->findRecord(RfcLogLevel::ERROR, 'could not be built');
    $this->assertNotNull($parent);
    $this->assertSame('field_content_paragraphs[0]', $parent['context']['@path']);
    $this->assertSame('section', $parent['context']['@bundle']);
  }

  /**
   * Tests that constraint violations are logged and do not block assembly.
   */
  public function testViolationsAreLoggedWithoutBlocking(): void {
    $node = $this->assembler()->assemble('oe_news', [
      'title' => [['value' => str_repeat('a', 300)]],
    ], NULL);

    $this->assertTrue($node->isNew(), 'The invalid draft is still returned.');

    $record = $this->findRecord(RfcLogLevel::WARNING, 'is invalid at');
    $this->assertNotNull($record, 'A constraint violation is logged as a warning.');
    $this->assertSame('title.0.value', $record['context']['@path']);
    $this->assertSame('oe_news', $record['context']['@bundle']);
  }

  /**
   * Tests that violations inside an inline child carry the nesting path.
   */
  public function testChildViolationsCarryTheirNestingPath(): void {
    $this->assembler()->assemble('oe_news', [
      'title' => [['value' => 'Valid title']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'quote_block']],
          'field_quote_attribution' => [['value' => str_repeat('b', 300)]],
        ],
      ],
    ], NULL);

    $record = $this->findRecord(RfcLogLevel::WARNING, 'is invalid at');
    $this->assertNotNull($record);
    $this->assertSame(
      'field_content_paragraphs[0].field_quote_attribution.0.value',
      $record['context']['@path'],
    );
  }

  /**
   * Returns the assembler under test.
   */
  private function assembler(): DraftAssemblerInterface {
    return $this->container->get(DraftAssemblerInterface::class);
  }

  /**
   * Returns the inline entity hydrator under test.
   */
  private function hydrator(): InlineEntityHydrator {
    return $this->container->get(InlineEntityHydrator::class);
  }

  /**
   * Returns the first captured record of a level whose message contains text.
   *
   * @param int $level
   *   An RfcLogLevel severity.
   * @param string $needle
   *   A fragment of the untranslated message.
   *
   * @return array|null
   *   The record, or NULL when nothing matched.
   */
  private function findRecord(int $level, string $needle): ?array {
    foreach ($this->logger->records as $record) {
      if ($record['level'] === $level && str_contains((string) $record['message'], $needle)) {
        return $record;
      }
    }
    return NULL;
  }

  /**
   * Creates and saves an oe_contact node to reference by uuid.
   */
  private function createContactNode(): NodeInterface {
    $node = Node::create([
      'type' => 'oe_contact',
      'title' => 'Contact node',
      'field_contact_name' => 'Jane Doe',
    ]);
    $node->save();
    return $node;
  }

}
