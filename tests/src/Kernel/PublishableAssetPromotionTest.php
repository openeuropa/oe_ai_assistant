<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\PublishableAssetRepository;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that publishing a node promotes its attached asset files to public.
 *
 * Covers the OEL-4843 steps 5-6 goal checks on the non-moderated path: the
 * transition to published moves the referenced asset's file from private to
 * public, leaves an unreferenced asset private, and detaches the promoted
 * asset so the session-delete cascade no longer reaps it.
 */
#[Group('oe_ai_assistant')]
class PublishableAssetPromotionTest extends AiEditorialSessionKernelTestBase {

  /**
   * The media reference field added to oe_news for the test.
   */
  private const string ASSET_FIELD = 'field_test_asset';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
    $privatePath = $this->siteDirectory . '/private';
    mkdir($privatePath);
    $this->setSetting('file_private_path', $privatePath);

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
   * Publishing promotes the referenced asset and spares the unreferenced one.
   */
  public function testPublishPromotesAttachedAssets(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $attached = $this->createAsset($session, 'attached');
    $unused = $this->createAsset($session, 'unused');

    // An unpublished node that references only the attached asset.
    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Draft node',
      'status' => 0,
      self::ASSET_FIELD => ['target_id' => $attached->id()],
    ]);
    $node->save();
    $session->setNode((int) $node->id())->save();

    // Both assets are still private and attached before publishing.
    $this->assertStringStartsWith('private://', (string) $this->reloadFile($attached)->getFileUri());

    // Publish: the node_update hook fires on the transition.
    $node->setPublished();
    $node->save();

    // The referenced asset's file is now public and detached from session.
    $promoted = $this->reloadFile($attached);
    $this->assertStringStartsWith('public://ai-publishable-assets/', (string) $promoted->getFileUri());
    $this->assertSame('', (string) $this->reload($attached)->get(PublishableAssetRepository::SESSION_FIELD)->target_id);

    // The unreferenced asset stays private and attached to the session.
    $this->assertStringStartsWith('private://', (string) $this->reloadFile($unused)->getFileUri());
    $this->assertSame(
      (string) $session->id(),
      (string) $this->reload($unused)->get(PublishableAssetRepository::SESSION_FIELD)->target_id,
    );
  }

  /**
   * A draft (unpublished) save promotes nothing.
   */
  public function testUnpublishedSaveDoesNotPromote(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $attached = $this->createAsset($session, 'attached');

    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Draft node',
      'status' => 0,
      self::ASSET_FIELD => ['target_id' => $attached->id()],
    ]);
    $node->save();
    $session->setNode((int) $node->id())->save();

    // A second unpublished save must not move anything.
    $node->setTitle('Draft node edited');
    $node->save();

    $this->assertStringStartsWith('private://', (string) $this->reloadFile($attached)->getFileUri());
  }

  /**
   * A promoted asset survives deletion of its originating session.
   */
  public function testPromotedAssetSurvivesSessionDeletion(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $attached = $this->createAsset($session, 'attached');

    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Draft node',
      'status' => 0,
      self::ASSET_FIELD => ['target_id' => $attached->id()],
    ]);
    $node->save();
    $session->setNode((int) $node->id())->save();

    // Publish as a later save, the way the drafting flow does: the node is
    // created as a draft first, then published once the session links it.
    $node->setPublished();
    $node->save();

    $promotedUri = (string) $this->reloadFile($attached)->getFileUri();
    $this->assertStringStartsWith('public://', $promotedUri);

    // Deleting the session must leave the promoted media and its file alone.
    $session->delete();

    $this->assertInstanceOf(Media::class, $this->reload($attached));
    $this->assertInstanceOf(FileInterface::class, $this->reloadFile($attached));
  }

  /**
   * Creates a publishable-asset media backed by a private file.
   */
  private function createAsset(AiEditorialSessionInterface $session, string $slug): Media {
    $directory = 'private://ai-publishable-assets';
    $this->container->get('file_system')->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY,
    );
    $uri = $directory . '/' . $slug . '-' . $session->id() . '.txt';
    file_put_contents($uri, 'Asset contents.');

    $file = File::create(['uri' => $uri, 'filename' => $slug . '.txt']);
    $file->setPermanent();
    $file->save();

    $media = Media::create([
      'bundle' => 'ai_publishable_asset',
      'name' => $slug . '.txt',
      'status' => 0,
      'oe_ai_session' => ['target_id' => $session->id()],
      'oe_ai_publishable_asset' => ['target_id' => $file->id()],
    ]);
    $media->save();

    return $media;
  }

  /**
   * Reloads a media entity from storage.
   */
  private function reload(Media $media): Media {
    $this->container->get('entity_type.manager')->getStorage('media')->resetCache();
    return Media::load($media->id());
  }

  /**
   * Reloads the file backing a media asset from storage.
   */
  private function reloadFile(Media $media): FileInterface {
    $fileId = $this->reload($media)->get('oe_ai_publishable_asset')->target_id;
    $this->container->get('entity_type.manager')->getStorage('file')->resetCache();
    return File::load($fileId);
  }

}
