<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Notes plugin: CRUD operations for user notes via Drupal State API.
 *
 * This is a development/testing plugin that demonstrates the RPC-style
 * plugin pattern with persistent storage.
 *
 * Actions exposed:
 *   /api/ai/plugins/notes/list   -- returns all notes for the current user
 *   /api/ai/plugins/notes/get    -- returns a single note by ID
 *   /api/ai/plugins/notes/create -- creates a new note
 *   /api/ai/plugins/notes/update -- updates title and content of a note
 *   /api/ai/plugins/notes/delete -- removes a note
 */
#[AiEditorialAssistant(
  id: 'notes',
  label: 'Notes',
  description: 'Simple note-taking with CRUD operations.',
)]
class NotesPlugin extends AiAssistantPluginBase {

  /**
   * Constructs a NotesPlugin.
   *
   * @param array $configuration
   *   Plugin configuration from the plugin manager.
   * @param string $plugin_id
   *   The plugin ID as declared in the AiEditorialAssistant attribute.
   * @param mixed $plugin_definition
   *   The plugin definition as resolved by the plugin manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal State API service, used as the persistence backend.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The currently authenticated Drupal user; notes are scoped per user.
   */
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
   *
   * Wires in the State API and current user from the service container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   *
   * @return static
   *   A new instance of this plugin with all dependencies injected.
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
   *
   * @return array<string, callable>
   *   Map of action name to callable.
   */
  public function getActionMap(): array {
    return [
      'list' => $this->list(...),
      'get' => $this->get(...),
      // The action is named "create" in the API but the method is called
      // createNote() because "create" is already taken by the static factory.
      'create' => $this->createNote(...),
      'update' => $this->update(...),
      'delete' => $this->delete(...),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Maps each action to its JSON Schema name for request body validation.
   * All notes actions require a schema; none perform ad-hoc validation.
   *
   * @return array<string, string>
   *   Map of action name to schema name.
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
   *
   * Returns the most recently created notes first so the frontend can
   * display a chronological list without client-side sorting.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (body is validated against NotesListRequest).
   *
   * @return array<int, array<string, mixed>>
   *   Numerically indexed array of note arrays, newest first.
   */
  public function list(Request $request): array {
    $notes = $this->loadNotes();

    // Sort by createdAt descending using lexicographic ISO-8601 comparison.
    // Since all timestamps are stored in "Y-m-d\TH:i:s\Z" format, strcmp()
    // correctly orders them without parsing.
    usort($notes, fn(array $a, array $b) => strcmp($b['createdAt'], $a['createdAt']));

    return $notes;
  }

  /**
   * Gets a single note by noteId.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (body validated against NotesGetRequest).
   *   Must contain a "noteId" key.
   *
   * @return array<string, mixed>
   *   The note array with id, title, content, createdAt, and updatedAt.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   With code "not_found" (HTTP 404) if no note with the given ID exists.
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
   * Generates a random 16-character hexadecimal ID and records creation
   * and modification timestamps in UTC ISO-8601 format.
   *
   * Named createNote() to avoid collision with the static create()
   * factory method from ContainerFactoryPluginInterface.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (body validated against NotesCreateRequest).
   *   Must contain "title" and "content" keys.
   *
   * @return array<string, mixed>
   *   The newly created note array including generated id, createdAt, and
   *   updatedAt fields.
   */
  public function createNote(Request $request): array {
    $body = $this->decodeJsonBody($request);

    // Record the current UTC time once so that createdAt and updatedAt
    // are always identical on creation (no clock skew between two calls).
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $note = [
      // Generate a random 8-byte (16 hex char) ID. This is not a UUID
      // but is sufficiently random for the dev-only notes store.
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
   *
   * Overwrites the "title" and "content" of the note identified by noteId
   * and refreshes the "updatedAt" timestamp. The "id" and "createdAt"
   * fields are never modified.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (body validated against NotesUpdateRequest).
   *   Must contain "noteId", "title", and "content" keys.
   *
   * @return array<string, mixed>
   *   The updated note array.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   With code "not_found" (HTTP 404) if no note with the given ID exists.
   */
  public function update(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $noteId = $body['noteId'];

    $notes = $this->loadNotes();
    $index = $this->findNoteIndex($notes, $noteId);

    if ($index === NULL) {
      throw new ActionException('not_found', "Note '{$noteId}' not found.", 404);
    }

    // Patch only the mutable fields; id and createdAt remain unchanged.
    $notes[$index]['title'] = $body['title'];
    $notes[$index]['content'] = $body['content'];
    $notes[$index]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

    $this->saveNotes($notes);

    return $notes[$index];
  }

  /**
   * Deletes a note by noteId.
   *
   * Removes the note from the array and re-persists the remaining notes.
   * Uses array_splice() to preserve sequential integer keys after removal.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request (body validated against NotesDeleteRequest).
   *   Must contain a "noteId" key.
   *
   * @return array<mixed>
   *   An empty array, indicating successful deletion with no response body.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   With code "not_found" (HTTP 404) if no note with the given ID exists.
   */
  public function delete(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $noteId = $body['noteId'];

    $notes = $this->loadNotes();
    $index = $this->findNoteIndex($notes, $noteId);

    if ($index === NULL) {
      throw new ActionException('not_found', "Note '{$noteId}' not found.", 404);
    }

    // array_splice() removes the element at $index and re-indexes the
    // array, keeping numeric keys sequential for consistent serialisation.
    array_splice($notes, $index, 1);
    $this->saveNotes($notes);

    return [];
  }

  /**
   * Returns the State API key for the current user's notes.
   *
   * Notes are scoped per-user by appending the user ID to the key prefix.
   * This prevents one user from reading or modifying another user's notes.
   *
   * @return string
   *   The state key, e.g. "oe_ai_assistant.notes.42".
   */
  private function getStateKey(): string {
    return 'oe_ai_assistant.notes.' . $this->currentUser->id();
  }

  /**
   * Loads all notes for the current user from State API.
   *
   * Returns an empty array when no notes have been saved yet, which is
   * the default value provided to State::get().
   *
   * @return array<int, array<string, mixed>>
   *   Numerically indexed array of note arrays, in storage order.
   */
  private function loadNotes(): array {
    return $this->state->get($this->getStateKey(), []);
  }

  /**
   * Saves all notes for the current user to State API.
   *
   * Replaces the entire notes array in a single State::set() call.
   * This is safe for the dev-only use case; a production implementation
   * would use a proper entity or database table.
   *
   * @param array<int, array<string, mixed>> $notes
   *   The full array of notes to persist.
   */
  private function saveNotes(array $notes): void {
    $this->state->set($this->getStateKey(), $notes);
  }

  /**
   * Finds a note by ID.
   *
   * Performs a linear scan of the notes array. Acceptable because the
   * number of notes per user is expected to be small in this dev plugin.
   *
   * @param string $noteId
   *   The note ID to search for.
   *
   * @return array<string, mixed>|null
   *   The note array if found, or NULL if no match exists.
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
   * Returns the integer offset within the $notes array rather than the
   * note itself, so callers can perform in-place mutations (update/delete).
   *
   * @param array<int, array<string, mixed>> $notes
   *   The full notes array to search within.
   * @param string $noteId
   *   The note ID to search for.
   *
   * @return int|null
   *   The zero-based array index of the matching note, or NULL if not found.
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
