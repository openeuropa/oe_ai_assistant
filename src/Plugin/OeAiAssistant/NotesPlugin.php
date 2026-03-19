<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\OeAiAssistant;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oe_ai_assistant\Annotation\AiAssistantPlugin;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Notes plugin: CRUD operations for user notes via Drupal State API.
 *
 * This is a development/testing plugin that demonstrates the RPC-style
 * plugin pattern with persistent storage.
 */
#[AiAssistantPlugin(
  id: 'notes',
  label: 'Notes',
  description: 'Simple note-taking with CRUD operations.',
)]
class NotesPlugin extends AiAssistantPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly StateInterface $state,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('state'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The "create" action maps to createNote() to avoid collision with
   * the static create() factory from ContainerFactoryPluginInterface.
   */
  public function getActionMap(): array {
    return [
      'list' => $this->list(...),
      'get' => $this->get(...),
      'create' => $this->createNote(...),
      'update' => $this->update(...),
      'delete' => $this->delete(...),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    return [
      'list' => 'NotesListRequest',
      'get' => 'NotesGetRequest',
      'create' => 'NotesCreateRequest',
      'update' => 'NotesUpdateRequest',
      'delete' => 'NotesDeleteRequest',
    ];
  }

  /**
   * Lists all notes for the current user, sorted by createdAt DESC.
   */
  public function list(Request $request): array {
    $notes = $this->loadNotes();

    usort($notes, fn(array $a, array $b) => strcmp($b['createdAt'], $a['createdAt']));

    return $notes;
  }

  /**
   * Gets a single note by noteId.
   */
  public function get(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $noteId = $body['noteId'];

    $note = $this->findNote($noteId);
    if ($note === NULL) {
      throw new ActionException('not_found', "Note '{$noteId}' not found.", 404);
    }

    return $note;
  }

  /**
   * Creates a new note.
   *
   * Named createNote() to avoid collision with the static create()
   * factory method from ContainerFactoryPluginInterface.
   */
  public function createNote(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $note = [
      'id' => bin2hex(random_bytes(8)),
      'title' => $body['title'],
      'content' => $body['content'],
      'createdAt' => $now,
      'updatedAt' => $now,
    ];

    $notes = $this->loadNotes();
    $notes[] = $note;
    $this->saveNotes($notes);

    return $note;
  }

  /**
   * Updates an existing note.
   */
  public function update(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $noteId = $body['noteId'];

    $notes = $this->loadNotes();
    $index = $this->findNoteIndex($notes, $noteId);

    if ($index === NULL) {
      throw new ActionException('not_found', "Note '{$noteId}' not found.", 404);
    }

    $notes[$index]['title'] = $body['title'];
    $notes[$index]['content'] = $body['content'];
    $notes[$index]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

    $this->saveNotes($notes);

    return $notes[$index];
  }

  /**
   * Deletes a note by noteId.
   */
  public function delete(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $noteId = $body['noteId'];

    $notes = $this->loadNotes();
    $index = $this->findNoteIndex($notes, $noteId);

    if ($index === NULL) {
      throw new ActionException('not_found', "Note '{$noteId}' not found.", 404);
    }

    array_splice($notes, $index, 1);
    $this->saveNotes($notes);

    return [];
  }

  /**
   * Returns the State API key for the current user's notes.
   */
  private function getStateKey(): string {
    return 'oe_ai_assistant.notes.' . $this->currentUser->id();
  }

  /**
   * Loads all notes for the current user from State API.
   *
   * @return array<int, array<string, mixed>>
   */
  private function loadNotes(): array {
    return $this->state->get($this->getStateKey(), []);
  }

  /**
   * Saves all notes for the current user to State API.
   *
   * @param array<int, array<string, mixed>> $notes
   */
  private function saveNotes(array $notes): void {
    $this->state->set($this->getStateKey(), $notes);
  }

  /**
   * Finds a note by ID.
   *
   * @return array<string, mixed>|null
   */
  private function findNote(string $noteId): ?array {
    foreach ($this->loadNotes() as $note) {
      if ($note['id'] === $noteId) {
        return $note;
      }
    }
    return NULL;
  }

  /**
   * Finds a note's index by ID.
   *
   * @param array<int, array<string, mixed>> $notes
   */
  private function findNoteIndex(array $notes, string $noteId): ?int {
    foreach ($notes as $index => $note) {
      if ($note['id'] === $noteId) {
        return $index;
      }
    }
    return NULL;
  }

}
