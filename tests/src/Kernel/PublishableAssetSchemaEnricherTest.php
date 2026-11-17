<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the schema tool advertises only the session's attachable assets.
 *
 * Covers the OEL-4843 step 3 goal check: a media reference field on the
 * drafted content type carries an enum of the session's publishable asset
 * ids, and never an asset of another session.
 */
#[Group('oe_ai_assistant')]
class PublishableAssetSchemaEnricherTest extends AiEditorialSessionKernelTestBase {

  /**
   * The media reference field added to oe_news for the test.
   */
  private const string ASSET_FIELD = 'field_test_asset';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
    // A media reference field on the drafted bundle that accepts the
    // publishable-asset bundle; this is the field an editor would add to
    // receive a drafted asset.
    FieldStorageConfig::create([
      'field_name' => self::ASSET_FIELD,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => self::ASSET_FIELD,
      'entity_type' => 'node',
      'bundle' => 'oe_news',
      'label' => 'Attached asset',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => ['target_bundles' => ['ai_publishable_asset' => 'ai_publishable_asset']],
      ],
    ])->save();
  }

  /**
   * The media field is pinned to the session's assets, foreign ones excluded.
   */
  public function testSchemaPinsSessionAssets(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $otherSession = $this->createSession($owner);

    $mine = $this->createAsset($session);
    // An asset of another session must never appear in this session's enum.
    $this->createAsset($otherSession);

    $targetId = $this->assetFieldTargetId((string) $session->id());

    $this->assertArrayHasKey('enum', $targetId);
    $this->assertSame([(int) $mine->id()], $targetId['enum']);
    $this->assertArrayHasKey('description', $targetId);
  }

  /**
   * With no assets on the session, the field is marked empty, not enum-pinned.
   */
  public function testSchemaWithoutAssetsMarksFieldEmpty(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $targetId = $this->assetFieldTargetId((string) $session->id());

    $this->assertArrayNotHasKey('enum', $targetId);
    $this->assertArrayHasKey('description', $targetId);
  }

  /**
   * Creates a publishable-asset media owned by the session.
   */
  private function createAsset(AiEditorialSessionInterface $session): Media {
    $file = File::create([
      'uri' => 'public://asset-' . $session->id() . '.txt',
      'filename' => 'asset.txt',
    ]);
    $file->setPermanent();
    $file->save();

    $media = Media::create([
      'bundle' => 'ai_publishable_asset',
      'name' => 'asset.txt',
      'status' => 0,
      'oe_ai_session' => ['target_id' => $session->id()],
      'oe_ai_publishable_asset' => ['target_id' => $file->id()],
    ]);
    $media->save();

    return $media;
  }

  /**
   * Runs the schema tool and returns the asset field's target_id schema.
   *
   * @param string $sessionId
   *   The session id to pin.
   *
   * @return array
   *   The items.properties.target_id schema of the asset field.
   */
  private function assetFieldTargetId(string $sessionId): array {
    $plugin = $this->container->get('plugin.manager.ai.function_calls')
      ->createInstance('oe_ai_assistant:get_content_schema');
    $plugin->setContextValue('entity_type_id', 'node');
    $plugin->setContextValue('bundle', 'oe_news');
    $plugin->setContextValue('session_id', $sessionId);
    $plugin->execute();
    $groups = $plugin->getStructuredOutput();

    foreach ($groups as $group) {
      $field = $group['schemaSlice']['properties'][self::ASSET_FIELD] ?? NULL;
      if ($field !== NULL) {
        return $field['items']['properties']['target_id'];
      }
    }
    $this->fail(sprintf('Field "%s" was not found in any schema group.', self::ASSET_FIELD));
  }

}
