<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSession;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Contains hooks regarding editorial session entities.
 */
final class EditorialSessionHooks {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: ContextDocumentRepository::class)]
    private readonly DocumentRepositoryInterface $contextDocumentRepository,
  ) {}

  /**
   * Implements hook_entity_bundle_info_alter().
   *
   * Gives the content creation session its bundle class, which answers what
   * the session drafts with and what it has drafted.
   */
  #[Hook('entity_bundle_info_alter')]
  public function setContentCreationBundleClass(array &$bundles): void {
    if (isset($bundles['ai_editorial_session']['content_creation'])) {
      $bundles['ai_editorial_session']['content_creation']['class'] = ContentCreationSession::class;
    }
  }

  /**
   * Implements hook_ai_editorial_session_delete().
   *
   * Removes the conversation hosted by the deleted session.
   */
  #[Hook('ai_editorial_session_delete')]
  public function deleteSessionNeuronMessages(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    $entity->deleteConversation();
  }

  /**
   * Implements hook_ai_editorial_session_delete().
   *
   * Removes the private context documents of the deleted session, together
   * with their managed files.
   */
  #[Hook('ai_editorial_session_delete')]
  public function deleteSessionContextDocuments(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    $this->contextDocumentRepository->deleteForSession($entity);
  }

  /**
   * Implements hook_ai_editorial_session_delete().
   *
   * Removes the activity recorded for the deleted session.
   */
  #[Hook('ai_editorial_session_delete')]
  public function deleteSessionActivity(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    $entity->deleteActivity();
  }

}
