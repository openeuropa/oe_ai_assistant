<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaTypeInterface;
use Drupal\node\Entity\NodeType;
use Drupal\oe_ai_assistant\Plugin\AiFunctionCall\LookupMedia;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests the lookup_media function-call plugin.
 *
 * @group oe_ai_assistant
 */
final class LookupMediaTest extends KernelTestBase {

  /**
   * The enabled modules.
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'node',
    'workflows',
    'content_moderation',
    'media',
    'media_test_source',
    'key',
    'ai',
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
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * The created media IDs keyed by media name.
   *
   * @var array<string, int>
   */
  protected array $mediaIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig([
      'field',
      'file',
      'image',
      'media',
      'node',
    ]);

    $this->functionCallManager = $this->container
      ->get('plugin.manager.ai.function_calls');
    $this->currentUser = $this->container->get('current_user');
    $this->entityDisplayRepository = $this->container
      ->get('entity_display.repository');

    NodeType::create([
      'type' => 'oe_news',
      'name' => 'OE News',
    ])->save();

    $imageType = $this->createMediaType('image', 'Image');
    $documentType = $this->createMediaType('document', 'Document');
    $audioType = $this->createMediaType('audio', 'Audio');

    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'field_media_assets',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => [
        'target_type' => 'media',
      ],
    ]);
    $fieldStorage->save();

    FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => 'oe_news',
      'label' => 'Media assets',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [
            'document' => 'document',
            'image' => 'image',
          ],
        ],
      ],
    ])->save();

    $role = Role::create([
      'id' => 'oe_editor',
      'label' => 'OE Editor',
    ]);
    $role->grantPermission('create oe_news content');
    $role->grantPermission('view media');
    $role->save();

    $user = User::create([
      'name' => 'oe_editor',
      'mail' => 'oe_editor@example.com',
      'status' => 1,
      'roles' => ['oe_editor'],
    ]);
    $user->save();

    $this->currentUser->setAccount($user);

    $this->mediaIds['Autumn Sunrise'] = (int) $this
      ->createMedia($imageType, 'Autumn Sunrise')
      ->id();
    $this->mediaIds['Autumn Briefing'] = (int) $this
      ->createMedia($documentType, 'Autumn Briefing')
      ->id();
    $this->mediaIds['Winter Photo'] = (int) $this
      ->createMedia($imageType, 'Winter Photo')
      ->id();
    $this->mediaIds['Autumn Podcast'] = (int) $this
      ->createMedia($audioType, 'Autumn Podcast')
      ->id();
  }

  /**
   * Tests plugin discovery and media lookup results.
   */
  public function testLookupMedia(): void {
    $this->assertTrue(
      $this->functionCallManager->functionExists('lookup_media'),
    );

    $definition = $this->functionCallManager
      ->getDefinition('oe_ai_assistant:lookup_media');
    $this->assertSame('oe_ai_assistant', $definition['group']);
    $this->assertSame('lookup_media', $definition['function_name']);

    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName('lookup_media');
    $this->assertInstanceOf(LookupMedia::class, $plugin);
    $this->assertInstanceOf(
      StructuredExecutableFunctionCallInterface::class,
      $plugin,
    );

    $plugin->setContextValue('entity_type', 'node');
    $plugin->setContextValue('bundle', 'oe_news');
    $plugin->setContextValue('field_name', 'field_media_assets');
    $plugin->setContextValue('query', 'Autumn');
    $plugin->execute();

    $expected = [
      'media' => [
        [
          'target_id' => $this->mediaIds['Autumn Briefing'],
          'label' => 'Autumn Briefing',
          'bundle' => 'document',
        ],
        [
          'target_id' => $this->mediaIds['Autumn Sunrise'],
          'label' => 'Autumn Sunrise',
          'bundle' => 'image',
        ],
      ],
    ];

    $this->assertSame($expected, $plugin->getStructuredOutput());
    $this->assertSame(
      Json::encode($expected),
      $plugin->getReadableOutput(),
    );
  }

  /**
   * Tests that the optional limit reduces the result set.
   */
  public function testLookupMediaLimit(): void {
    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName('lookup_media');

    $plugin->setContextValue('entity_type', 'node');
    $plugin->setContextValue('bundle', 'oe_news');
    $plugin->setContextValue('field_name', 'field_media_assets');
    $plugin->setContextValue('query', 'Autumn');
    $plugin->setContextValue('limit', 1);
    $plugin->execute();

    $this->assertSame([
      'media' => [
        [
          'target_id' => $this->mediaIds['Autumn Briefing'],
          'label' => 'Autumn Briefing',
          'bundle' => 'document',
        ],
      ],
    ], $plugin->getStructuredOutput());
  }

  /**
   * Creates a media type and its source field.
   *
   * @param string $id
   *   The media type ID.
   * @param string $label
   *   The media type label.
   *
   * @return \Drupal\media\MediaTypeInterface
   *   The created media type.
   */
  private function createMediaType(string $id, string $label): MediaTypeInterface {
    $mediaType = MediaType::create([
      'id' => $id,
      'label' => $label,
      'source' => 'test',
    ]);

    $source = $mediaType->getSource();
    $sourceField = $source->createSourceField($mediaType);
    $sourceConfiguration = $source->getConfiguration();
    $sourceConfiguration['source_field'] = $sourceField->getName();
    $source->setConfiguration($sourceConfiguration);
    $mediaType->save();

    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();

    $formDisplay = $this->entityDisplayRepository
      ->getFormDisplay('media', $mediaType->id(), 'default');
    $source->prepareFormDisplay($mediaType, $formDisplay);
    $formDisplay->save();

    return $mediaType;
  }

  /**
   * Creates and saves a media entity for the given media type.
   *
   * @param \Drupal\media\MediaTypeInterface $mediaType
   *   The media type.
   * @param string $name
   *   The media name.
   *
   * @return \Drupal\media\Entity\Media
   *   The created media entity.
   */
  private function createMedia(MediaTypeInterface $mediaType, string $name): Media {
    $sourceFieldName = $mediaType
      ->getSource()
      ->getSourceFieldDefinition($mediaType)
      ->getName();

    $media = Media::create([
      'bundle' => $mediaType->id(),
      'name' => $name,
      'status' => 1,
      'uid' => $this->currentUser->id(),
      $sourceFieldName => $name . ' source',
    ]);
    $media->save();

    return $media;
  }

}
