<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moves a published node's attached asset files from private to public.
 *
 * The attached set is derived, not stored: it is the intersection of the
 * node's media references and the session's publishable assets (reverse
 * lookup from the node). Each promoted asset is detached from the session so
 * the session-delete cascade no longer owns the file now serving published
 * content.
 */
class PublishableAssetPromoter implements PublishableAssetPromoterInterface {

  /**
   * The public directory promoted asset files are moved under.
   */
  private const string PUBLIC_DIRECTORY = 'public://ai-publishable-assets';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly PublishableAssetRepository $publishableAssetRepository,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function promoteForNode(NodeInterface $node): void {
    $session = $this->resolveSession($node);
    if ($session === NULL) {
      return;
    }

    $allowedIds = $this->publishableAssetRepository->getSessionAssetIds($session);
    if ($allowedIds === []) {
      return;
    }

    // The attached set is the node's media references that are also this
    // session's publishable assets. Media the node references but the session
    // does not own (and assets the session owns but the node does not use)
    // are left out.
    foreach ($this->referencedMediaIds($node) as $mediaId) {
      if (!in_array($mediaId, $allowedIds, TRUE)) {
        continue;
      }
      $asset = $this->publishableAssetRepository->loadSessionAsset($session, (string) $mediaId);
      if ($asset instanceof MediaInterface) {
        $this->promoteAsset($session, $asset);
      }
    }
  }

  /**
   * Promotes one asset: moves its file to public and detaches the session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The owning session, cleared from the asset once the file is public.
   * @param \Drupal\media\MediaInterface $asset
   *   The publishable asset media to promote.
   */
  private function promoteAsset(AiEditorialSessionInterface $session, MediaInterface $asset): void {
    $file = $this->publishableAssetRepository->getAssetFile($asset);
    if (!$file instanceof FileInterface) {
      return;
    }

    $uri = (string) $file->getFileUri();
    // Already public (e.g. the node is being republished): nothing to move,
    // but still make sure the asset no longer rides on the session.
    if (!str_starts_with($uri, 'private://')) {
      $this->detachFromSession($session, $asset);
      return;
    }

    try {
      $destination = self::PUBLIC_DIRECTORY;
      // FileRepository::move() does not create the destination directory, so
      // prepare it first; the first promotion on a site creates it.
      if (!$this->fileSystem->prepareDirectory(
        $destination,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
      )) {
        throw new \RuntimeException(sprintf('Could not prepare %s.', self::PUBLIC_DIRECTORY));
      }
      $this->fileRepository->move($file, $this->publicDestination($uri), FileExists::Rename);
    }
    catch (\Exception $e) {
      // A failed move must not block publishing; leave the asset private and
      // attached so a later republish can retry it.
      $this->logger->error('Could not promote publishable asset @id to public: @message', [
        '@id' => (string) $asset->id(),
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $this->detachFromSession($session, $asset);
  }

  /**
   * Builds the public destination uri for a promoted private file.
   *
   * Keeps the file name, placing it under the public asset directory, so a
   * promoted file lands at public://ai-publishable-assets/<basename>.
   *
   * @param string $privateUri
   *   The current private:// uri.
   *
   * @return string
   *   The target public:// uri.
   */
  private function publicDestination(string $privateUri): string {
    return self::PUBLIC_DIRECTORY . '/' . basename($privateUri);
  }

  /**
   * Clears the session back-reference so the delete cascade spares the asset.
   *
   * The session-delete cascade reaps media whose oe_ai_session still points
   * at the session. A promoted asset now backs published content, so it must
   * outlive the session; detaching it removes it from that cascade.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session being detached (used only to scope the log).
   * @param \Drupal\media\MediaInterface $asset
   *   The asset to detach and save.
   */
  private function detachFromSession(AiEditorialSessionInterface $session, MediaInterface $asset): void {
    if ($asset->hasField(PublishableAssetRepository::SESSION_FIELD)) {
      $asset->set(PublishableAssetRepository::SESSION_FIELD, NULL);
      $asset->save();
    }
  }

  /**
   * Collects the media ids a node references through its fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to inspect.
   *
   * @return int[]
   *   The referenced media entity ids, without duplicates.
   */
  private function referencedMediaIds(NodeInterface $node): array {
    $ids = [];
    foreach ($node->getFields() as $items) {
      if (!$items instanceof EntityReferenceFieldItemListInterface
        || $items->getFieldDefinition()->getFieldStorageDefinition()->getSetting('target_type') !== 'media'
      ) {
        continue;
      }
      foreach ($items as $item) {
        $targetId = $item->target_id;
        if ($targetId !== NULL && $targetId !== '') {
          $ids[(int) $targetId] = (int) $targetId;
        }
      }
    }
    return array_values($ids);
  }

  /**
   * Finds the editorial session whose node field points at this node.
   *
   * The link is one-directional (session -> node), so promotion looks the
   * session up by a reverse query. A node carries at most one session.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The published node.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface|null
   *   The owning session, or NULL when the node was not drafted in one.
   */
  private function resolveSession(NodeInterface $node): ?AiEditorialSessionInterface {
    $storage = $this->entityTypeManager->getStorage('ai_editorial_session');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('node', (int) $node->id())
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $session = $storage->load(reset($ids));
    return $session instanceof AiEditorialSessionInterface ? $session : NULL;
  }

}
