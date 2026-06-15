<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Saves a drafted node built from LLM-produced field values.
 *
 * Encapsulates the validation, entity building, post-processing
 * (owner assignment, moderation state), and save logic that was
 * previously in DraftingPlugin::save().
 *
 * Why this is a separate service:
 * - The save logic depends on 5 services (entityTypeManager,
 *   currentUser, moderationInformation, draftEntityBuilder,
 *   logger) that are only used by the save action, not by chat
 *   or reset. Extracting them reduces the plugin's dependency
 *   count.
 * - The validation rules (bundle exists, user has permission)
 *   and post-processing (owner, moderation state) can be unit
 *   tested with mocks without needing a running Drupal site.
 * - The save flow is independent of the AI/streaming stack
 *   and can evolve separately (e.g. adding revision log messages,
 *   audit trails, or preview rendering).
 */
class DraftSaver implements DraftSaverInterface {

  /**
   * Constructs a DraftSaver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   For loading node type storage and validating bundles.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The authenticated user who will own the saved node.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   For detecting moderated bundles.
   * @param \Drupal\oe_ai_assistant\Service\DraftEntityBuilder $draftEntityBuilder
   *   Builds an unsaved node from the LLM fields map.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly DraftEntityBuilder $draftEntityBuilder,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(string $bundle, array $fields): array {
    // Validate bundle exists.
    if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
      throw new ActionException('invalid_bundle',
        sprintf('Content type "%s" does not exist.', $bundle), 400);
    }

    // Check create permission.
    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new ActionException(
        'forbidden',
        sprintf('You do not have permission to create %s content.', $bundle),
        403,
      );
    }

    // Build the unsaved entity from LLM field values.
    try {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->draftEntityBuilder->fromLlmFields('node', $bundle, $fields);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to build draft entity: @e', [
        '@e' => (string) $e,
      ]);
      throw new ActionException(
        'invalid_payload',
        'The submitted draft payload could not be processed. See the system log for details.',
        400,
      );
    }

    // Set owner and moderation state.
    $node->setOwnerId((int) $this->currentUser->id());
    if ($this->moderationInformation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    else {
      $node->setPublished(FALSE);
    }

    // Save the node (atomic: parent's preSave chain saves any
    // inline children in the same transaction).
    $node->save();

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->buildPreviewUrl($node),
    ];
  }

  /**
   * Builds the preview URL for a freshly saved node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The newly saved node.
   *
   * @return string
   *   Relative URL for the node preview.
   */
  private function buildPreviewUrl(NodeInterface $node): string {
    return $this->moderationInformation->isModeratedEntity($node)
      ? '/node/' . $node->id() . '/latest'
      : '/node/' . $node->id();
  }

}
