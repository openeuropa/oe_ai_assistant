<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\ai_neuron\Entity\NeuronMessageStorageInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Utility\MessageContent;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AI editorial session entity.
 *
 * @ContentEntityType(
 *   id = "ai_editorial_session",
 *   label = @Translation("AI editorial session"),
 *   label_collection = @Translation("AI Editorial Sessions"),
 *   label_singular = @Translation("AI editorial session"),
 *   label_plural = @Translation("AI editorial sessions"),
 *   bundle_label = @Translation("AI editorial session type"),
 *   handlers = {
 *     "list_builder" = "Drupal\oe_ai_assistant\AiEditorialSessionListBuilder",
 *     "access" = "Drupal\oe_ai_assistant\AiEditorialSessionAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\oe_ai_assistant\Form\AiEditorialSessionAddForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\oe_ai_assistant\Routing\AiEditorialSessionHtmlRouteProvider",
 *     },
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "bundle" = "type",
 *     "label" = "label",
 *     "owner" = "uid",
 *   },
 *   base_table = "ai_editorial_session",
 *   admin_permission = "administer ai editorial sessions",
 *   collection_permission = "view_update own sessions",
 *   bundle_entity_type = "ai_editorial_session_type",
 *   links = {
 *     "canonical" = "/admin/content/ai/{ai_editorial_session}",
 *     "history" = "/admin/content/ai/{ai_editorial_session}/history",
 *     "add-page" = "/admin/content/ai/add",
 *     "add-form" = "/admin/content/ai/add/{ai_editorial_session_type}",
 *     "delete-form" = "/admin/content/ai/{ai_editorial_session}/delete",
 *     "collection" = "/admin/content/ai",
 *   },
 * )
 */
class AiEditorialSession extends ContentEntityBase implements AiEditorialSessionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * Session status: active.
   */
  public const STATUS_ACTIVE = 'active';

  /**
   * Session status: completed.
   */
  public const STATUS_COMPLETED = 'completed';

  /**
   * Session status: abandoned.
   */
  public const STATUS_ABANDONED = 'abandoned';

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId(0);
    }

    if ($this->get('status')->isEmpty()) {
      $this->set('status', self::STATUS_ACTIVE);
    }

    if ($this->get('label')->isEmpty()) {
      // Default label from the human readable bundle label, never the
      // bundle machine name.
      $bundle = \Drupal::entityTypeManager()
        ->getStorage('ai_editorial_session_type')
        ->load($this->bundle());
      $bundle_label = (string) ($bundle?->label() ?? $this->bundle());
      $date = \Drupal::service('date.formatter')->format(
        \Drupal::time()->getRequestTime(),
        'custom',
        'Y-m-d'
      );
      $this->set('label', $bundle_label . ' - ' . $date);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getContentType(): string {
    return (string) $this->get('content_type')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): AiEditorialSessionInterface {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getNode(): ?NodeInterface {
    $entity = $this->get('node')->entity;
    return $entity instanceof NodeInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setNode(int $nid): AiEditorialSessionInterface {
    $this->set('node', $nid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function conversationThreads(): array {
    $threads = [];
    foreach ($this->conversationRows() as $row) {
      $threads[(string) $row->get('thread_id')->value][] = $row;
    }

    return $threads;
  }

  /**
   * {@inheritdoc}
   */
  public function editorialThread(): string {
    return 'editorial.session_' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function conversationTurns(): array {
    $turns = [];
    foreach ($this->activeThread($this->editorialThread()) as $row) {
      $role = (string) $row->get('role')->value;
      if (!in_array($role, ['user', 'assistant'], TRUE)) {
        continue;
      }

      $text = MessageContent::toText((array) ($row->getContent() ?? []));
      // A tool result carries no content at all, so its text is empty and
      // the row is skipped.
      if ($text !== '') {
        $turns[] = ['role' => $role, 'text' => $text];
      }
    }

    return $turns;
  }

  /**
   * {@inheritdoc}
   */
  public function conversationThread(string $threadId): array {
    $rows = \Drupal::entityTypeManager()
      ->getStorage('neuron_message')
      ->loadByProperties(['oe_ai_session' => (int) $this->id(), 'thread_id' => $threadId]);
    // Keyed by row id, so sorting the keys is what puts the oldest first.
    ksort($rows);

    return array_values($rows);
  }

  /**
   * The rows of a thread that an agent still replays to the model.
   *
   * Neuron trims a thread to its agent's context window and stamps the rows
   * it drops with `archived_at`. Reading the whole thread gives us a prompt
   * that grows with the session, until it overflows the window the trim was
   * protecting. So we go through activeIds() and get what the trim left.
   *
   * @param string $threadId
   *   The thread to read.
   *
   * @return \Drupal\ai_neuron\Entity\NeuronMessageInterface[]
   *   The rows, oldest first.
   */
  private function activeThread(string $threadId): array {
    $storage = \Drupal::entityTypeManager()->getStorage('neuron_message');
    assert($storage instanceof NeuronMessageStorageInterface);

    return array_values($storage->loadMultiple($storage->activeIds($threadId)));
  }

  /**
   * {@inheritdoc}
   */
  public function conversationToolResults(): array {
    $results = [];
    foreach ($this->conversationRows() as $row) {
      $results = array_merge($results, $row->getToolResults());
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteConversation(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('neuron_message');
    $storage->delete($storage->loadByProperties(['oe_ai_session' => (int) $this->id()]));
  }

  /**
   * {@inheritdoc}
   */
  public function recordActivity(ActivityType $type, string $message, string|int|float ...$arguments): void {
    try {
      $this->activityStorage()->create([
        'session' => $this->id(),
        'type' => $type->value,
        'uid' => \Drupal::currentUser()->id(),
        // The created field defaults to the time the request came in. A
        // chat request can draft for a minute, so two drafts of one reply
        // would share one timestamp. We take the current time here.
        'created' => \Drupal::time()->getCurrentTime(),
        'message' => $arguments === [] ? $message : sprintf($message, ...$arguments),
      ])->save();
    }
    catch (\Throwable $e) {
      // The caller has already made the change it reports, for example
      // a saved tone. If this write throws, the editor gets a 500 for a
      // change that did save. So we log the failure and return.
      \Drupal::logger('oe_ai_assistant')->error('The activity of session @session could not be recorded: @message', [
        '@session' => (string) $this->id(),
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getLatestActivity(int $limit): array {
    $storage = $this->activityStorage();
    // Two entries can share a second, so we sort by id and not by the
    // created time. The query gives us the newest entries first, and
    // ksort() puts them back in the order they happened.
    $ids = $this->activityQuery()
      ->sort('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    $entries = $storage->loadMultiple($ids);
    ksort($entries);

    return array_values($entries);
  }

  /**
   * {@inheritdoc}
   */
  public function countActivity(): int {
    return (int) $this->activityQuery()->count()->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteActivity(): void {
    $storage = $this->activityStorage();
    $storage->delete($storage->loadMultiple($this->activityQuery()->execute()));
  }

  /**
   * Returns the storage that holds the activity entries.
   */
  private function activityStorage(): EntityStorageInterface {
    return \Drupal::entityTypeManager()->getStorage('ai_editorial_session_activity');
  }

  /**
   * Starts a query over the activity entries of this session.
   *
   * Callers reach these entries only through the session, and the
   * session's own access check has already run. So the query skips the
   * access check.
   */
  private function activityQuery(): QueryInterface {
    return $this->activityStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('session', (int) $this->id());
  }

  /**
   * The stored messages of this session, oldest first.
   *
   * Read by the reference the message store stamps on every row it writes, so
   * nothing here depends on how a thread ID is spelled.
   *
   * @return \Drupal\ai_neuron\Entity\NeuronMessageInterface[]
   *   The stored messages.
   */
  private function conversationRows(): array {
    $rows = \Drupal::entityTypeManager()
      ->getStorage('neuron_message')
      ->loadByProperties(['oe_ai_session' => (int) $this->id()]);
    // Keyed by row id, so sorting the keys is what puts the oldest first.
    ksort($rows);

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['type']
      ->setLabel(t('Session type'))
      ->setDescription(t('The AI editorial session bundle.'));

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('The human-readable label for the session.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setDescription(t('The user who created the session.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setDescription(t('The session lifecycle status.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          self::STATUS_ACTIVE => 'Active',
          self::STATUS_COMPLETED => 'Completed',
          self::STATUS_ABANDONED => 'Abandoned',
        ],
      ])
      ->setDefaultValue(self::STATUS_ACTIVE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the session was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the session was last edited.'));

    return $fields;
  }

}
