<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\oe_ai_assistant\Entity\ActivityType;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSession;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
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
   * Implements hook_ai_editorial_session_insert().
   *
   * The add form sets the tone and the template, and records no entry for
   * either. The first line of the record would then read "Tone changed
   * from Formal to Plain.", with nothing to say the session started on
   * Formal. So we record both values as the first entry.
   */
  #[Hook('ai_editorial_session_insert')]
  public function recordSessionCreation(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    if (!$entity instanceof ContentCreationSessionInterface) {
      $entity->recordActivity(ActivityType::SessionCreated, 'Session created.');

      return;
    }

    $entity->recordActivity(
      ActivityType::SessionCreated,
      'Session created with tone %s and template %s.',
      $this->selected($entity->getTone()),
      $this->selected($entity->getTemplate()),
    );
  }

  /**
   * Names a selection the editor made, or reports that they made none.
   *
   * @param array|null $selection
   *   The tone or the template, NULL when the editor picked none.
   *
   * @return string
   *   The label in quotes, or "not set".
   */
  private function selected(?array $selection): string {
    return $selection === NULL
      ? 'not set'
      : sprintf('"%s"', (string) ($selection['label'] ?? ''));
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
