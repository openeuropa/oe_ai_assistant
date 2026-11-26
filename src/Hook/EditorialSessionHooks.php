<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\oe_ai_assistant\Entity\ActivityType;
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
   * Implements hook_ai_editorial_session_insert().
   *
   * The add form sets the tone and the template, and records no entry for
   * either. The first line of the record would then read "Tone changed
   * from Formal to Plain.", with nothing to say the session started on
   * Formal. So we record both values as the first entry.
   *
   * Both getTone() and getTemplate() read a brief that the session caches
   * for the rest of the request, and nothing invalidates it. A call from
   * this hook fills that cache. A caller that sets the tone right after
   * the insert would then still read the old value. The brief also
   * resolves the field groups, which throws when the stored template is
   * not one the bundle can draft with. So we read the tone and the
   * template fields here.
   */
  #[Hook('ai_editorial_session_insert')]
  public function recordSessionCreation(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    // Only content creation sessions have the tone and template fields.
    if (!$entity->hasField('tone')) {
      $entity->recordActivity(ActivityType::SessionCreated, 'Session created.');

      return;
    }

    $entity->recordActivity(
      ActivityType::SessionCreated,
      'Session created with tone %s and template %s.',
      [$this->named($entity, 'tone'), $this->named($entity, 'template')],
    );
  }

  /**
   * Implements hook_ai_editorial_session_update().
   *
   * The tone and the template are fields, and every writer can set them: the
   * add form, the two plugin actions, a migration, a script. A writer that
   * records its own change leaves the record blind to the others, so the
   * change is recorded here, where every save passes.
   */
  #[Hook('ai_editorial_session_update')]
  public function recordSelectionChanges(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);
    $original = $entity->getOriginal();

    // Only content creation sessions have the tone and template fields, and
    // only an update has something to compare against.
    if (!$entity->hasField('tone') || !$original instanceof AiEditorialSessionInterface) {
      return;
    }

    $this->recordChange($entity, $original, 'tone', ActivityType::ToneChanged, 'Tone');
    $this->recordChange($entity, $original, 'template', ActivityType::TemplateChanged, 'Template');
  }

  /**
   * Records what one selection of the session changed from and to.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session as it was saved.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $original
   *   The session as it was before the save.
   * @param string $field
   *   The reference field that holds the selection.
   * @param \Drupal\oe_ai_assistant\Entity\ActivityType $type
   *   The kind of entry to record.
   * @param string $what
   *   The word that opens the sentence, "Tone" or "Template".
   */
  private function recordChange(
    AiEditorialSessionInterface $session,
    AiEditorialSessionInterface $original,
    string $field,
    ActivityType $type,
    string $what,
  ): void {
    $before = $this->selection($original, $field);
    $after = $this->selection($session, $field);
    if ($before['id'] === $after['id']) {
      return;
    }

    if ($after['id'] === '') {
      $session->recordActivity($type, '%s cleared.', [$what]);

      return;
    }
    if ($before['id'] === '') {
      $session->recordActivity($type, '%s set to "%s".', [$what, $after['label']]);

      return;
    }

    $session->recordActivity(
      $type,
      '%s changed from "%s" to "%s".',
      [$what, $before['label'], $after['label']],
    );
  }

  /**
   * Names the selection a field holds, or reports that it holds none.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   * @param string $field
   *   The reference field that holds the selection.
   *
   * @return string
   *   The label in quotes, or "not set".
   */
  private function named(AiEditorialSessionInterface $session, string $field): string {
    $selection = $this->selection($session, $field);

    return $selection['id'] === '' ? 'not set' : sprintf('"%s"', $selection['label']);
  }

  /**
   * Reads the id and the label of a selection.
   *
   * An entry names a tone or a template by the label the editor saw. A term
   * or a template the site deleted leaves no label to read, so the id
   * stands in for it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   * @param string $field
   *   The reference field that holds the selection.
   *
   * @return array
   *   The id of the selection, and the label to name it by.
   */
  private function selection(AiEditorialSessionInterface $session, string $field): array {
    $id = (string) $session->get($field)->target_id;
    $label = (string) ($session->get($field)->entity?->label() ?? '');

    return ['id' => $id, 'label' => $label !== '' ? $label : $id];
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
