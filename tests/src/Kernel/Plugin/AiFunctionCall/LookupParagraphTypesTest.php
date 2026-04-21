<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\oe_ai_assistant\Plugin\AiFunctionCall\LookupParagraphTypes;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests the lookup_paragraph_types function-call plugin.
 *
 * @group oe_ai_assistant
 */
final class LookupParagraphTypesTest extends KernelTestBase {

  /**
   * The enabled modules.
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'file',
    'key',
    'ai',
    'workflows',
    'content_moderation',
    'entity_reference_revisions',
    'paragraphs',
    'oe_ai_assistant',
  ];

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager
   */
  protected FunctionCallPluginManager $functionCallManager;

  /**
   * The current user proxy.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    \Drupal::moduleHandler()->loadInclude('paragraphs', 'install');

    $this->functionCallManager = $this->container
      ->get('plugin.manager.ai.function_calls');
    $this->currentUser = $this->container->get('current_user');

    NodeType::create([
      'type' => 'oe_news',
      'name' => 'OE News',
    ])->save();

    ParagraphsType::create([
      'id' => 'text_block',
      'label' => 'Text block',
    ])->save();
    ParagraphsType::create([
      'id' => 'quote_block',
      'label' => 'Quote block',
    ])->save();
    ParagraphsType::create([
      'id' => 'promo_block',
      'label' => 'Promo block',
    ])->save();

    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'field_content_paragraphs',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => [
        'target_type' => 'paragraph',
      ],
    ]);
    $fieldStorage->save();

    FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => 'oe_news',
      'label' => 'Content paragraphs',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => [
            'text_block' => 'text_block',
            'quote_block' => 'quote_block',
          ],
          'target_bundles_drag_drop' => [
            'quote_block' => [
              'enabled' => TRUE,
              'weight' => 0,
            ],
            'promo_block' => [
              'enabled' => FALSE,
              'weight' => 1,
            ],
            'text_block' => [
              'enabled' => TRUE,
              'weight' => 2,
            ],
          ],
        ],
      ],
    ])->save();

    $role = Role::create([
      'id' => 'oe_editor',
      'label' => 'OE Editor',
    ]);
    $role->grantPermission('create oe_news content');
    $role->save();

    $user = User::create([
      'name' => 'oe_editor',
      'mail' => 'oe_editor@example.com',
      'status' => 1,
      'roles' => ['oe_editor'],
    ]);
    $user->save();

    $this->currentUser->setAccount($user);
  }

  /**
   * Tests plugin discovery and field-scoped paragraph bundle lookup.
   */
  public function testLookupParagraphTypes(): void {
    $this->assertTrue(
      $this->functionCallManager->functionExists('lookup_paragraph_types'),
    );

    $definition = $this->functionCallManager
      ->getDefinition('oe_ai_assistant:lookup_paragraph_types');
    $this->assertSame('oe_ai_assistant', $definition['group']);
    $this->assertSame(
      'lookup_paragraph_types',
      $definition['function_name'],
    );

    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName('lookup_paragraph_types');
    $this->assertInstanceOf(LookupParagraphTypes::class, $plugin);
    $this->assertInstanceOf(
      StructuredExecutableFunctionCallInterface::class,
      $plugin,
    );

    $plugin->setContextValue('entity_type', 'node');
    $plugin->setContextValue('bundle', 'oe_news');
    $plugin->setContextValue('field_name', 'field_content_paragraphs');
    $plugin->execute();

    $expected = [
      'paragraph_types' => [
        [
          'bundle' => 'quote_block',
          'label' => 'Quote block',
        ],
        [
          'bundle' => 'text_block',
          'label' => 'Text block',
        ],
      ],
    ];

    $this->assertSame($expected, $plugin->getStructuredOutput());
    $this->assertSame(
      Json::encode($expected),
      $plugin->getReadableOutput(),
    );
  }

}
