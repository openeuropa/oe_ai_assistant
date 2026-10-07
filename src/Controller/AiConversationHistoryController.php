<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders every conversation a session produced, one table per thread.
 *
 * A session holds the editor's conversation and one thread per sub-agent run,
 * each addressed by its own thread id. Every row contributes a message row
 * with the scannable columns and a detail row with the stored content, the
 * message metadata and the telemetry. Expand and collapse come from the
 * session_history library; the server renders every row visible, so the page
 * stays readable without JavaScript.
 */
class AiConversationHistoryController extends ControllerBase {

  /**
   * The number of table columns, used to span detail rows across the table.
   */
  private const COLUMN_COUNT = 6;

  public function __construct(
    private readonly SessionConversation $conversation,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SessionConversation::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns the page title for the history route.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The session whose conversations are displayed.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function title(AiEditorialSessionInterface $ai_editorial_session): TranslatableMarkup {
    return $this->t('Conversation history: @label', ['@label' => $ai_editorial_session->label()]);
  }

  /**
   * Builds the conversation history page for a session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The session whose conversations are displayed.
   *
   * @return array
   *   The render array: session totals followed by one table per thread.
   */
  public function view(AiEditorialSessionInterface $ai_editorial_session): array {
    $threads = $this->conversation->threads($ai_editorial_session);

    $build = [];

    // Invalidate when the session changes or any message is written.
    $definition = $this->entityTypeManager()->getDefinition('neuron_message');
    CacheableMetadata::createFromObject($ai_editorial_session)
      ->addCacheContexts($definition->getListCacheContexts())
      ->addCacheTags($definition->getListCacheTags())
      ->applyTo($build);

    if ($threads === []) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('This session has no conversation messages yet.') . '</p>',
      ];
      return $build;
    }

    $rows = array_merge(...array_values($threads));
    $build['totals'] = [
      '#type' => 'item',
      '#title' => $this->t('Session totals'),
      '#plain_text' => $this->t('@messages messages in @threads conversations, @tokens tokens', [
        '@messages' => count($rows),
        '@threads' => count($threads),
        '@tokens' => $this->sumTokens($rows),
      ]),
    ];

    // The toolbar and the tables share one wrapper so the behavior can wire
    // the bulk button to them. The toolbar is rendered hidden: only the
    // behavior reveals it, since without JavaScript the page is already fully
    // expanded and the button would do nothing.
    $build['history'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-history']],
      'toolbar' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-history-toolbar'],
          'hidden' => 'hidden',
        ],
        'details_all' => $this->buildToolbarButton(
          'ai-history-details-all',
          $this->t('Show all details'),
          $this->t('Hide all details'),
        ),
      ],
      '#attached' => ['library' => ['oe_ai_assistant/session_history']],
    ];

    foreach ($threads as $threadId => $thread) {
      $build['history'][$threadId] = $this->buildThread($threadId, $thread);
    }

    return $build;
  }

  /**
   * Builds the table of one thread.
   *
   * @param string $threadId
   *   The thread id, which names the conversation.
   * @param array $rows
   *   The rows of the thread, oldest first.
   *
   * @return array
   *   The table render array.
   */
  private function buildThread(string $threadId, array $rows): array {
    $tableRows = [];
    foreach ($rows as $row) {
      $this->buildRows($row, $tableRows);
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('@thread (@count messages, @tokens tokens)', [
        '@thread' => $threadId,
        '@count' => count($rows),
        '@tokens' => $this->sumTokens($rows),
      ]),
      '#header' => [
        $this->t('Role'),
        $this->t('Turn'),
        $this->t('Agent'),
        $this->t('Author'),
        $this->t('Created'),
        $this->t('Content'),
      ],
      '#rows' => $tableRows,
      '#sticky' => TRUE,
      '#attributes' => ['class' => ['ai-history-table']],
    ];
  }

  /**
   * Builds one bulk toggle button for the toolbar.
   *
   * The button starts in the collapsed state; the behavior swaps its label
   * from the data attributes as it toggles.
   *
   * @param string $class
   *   The behavior hook class for the button.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $show
   *   The label while the button would reveal things.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $hide
   *   The label while the button would hide things.
   *
   * @return array
   *   The button render array.
   */
  private function buildToolbarButton(string $class, TranslatableMarkup $show, TranslatableMarkup $hide): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $show,
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--small', $class],
        'aria-expanded' => 'false',
        'data-label-show' => $show,
        'data-label-hide' => $hide,
      ],
    ];
  }

  /**
   * Appends the message row and the detail row of one message.
   *
   * The server renders both rows visible; the session_history behavior
   * collapses the details to their default state.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   * @param array $rows
   *   The table rows built so far, modified by reference.
   */
  private function buildRows(ContentEntityInterface $message, array &$rows): void {
    $id = (int) $message->id();
    [$kind, $label] = $this->kindOf($message);

    // The whole row is the detail toggle: focusable and announcing its
    // expanded state, so no dedicated inspect column is needed.
    $rows[] = [
      'id' => 'ai-history-message-' . $id,
      'class' => ['ai-history-message', 'ai-history-role-' . $kind],
      'data-message-id' => (string) $id,
      'data-parent-id' => '',
      'tabindex' => '0',
      'aria-expanded' => 'true',
      'aria-controls' => 'ai-history-detail-' . $id,
      'data' => [
        ['data' => ['#plain_text' => $label]],
        ['data' => ['#plain_text' => (string) $message->get('turn')->value]],
        ['data' => ['#plain_text' => (string) $message->get('agent_id')->value]],
        ['data' => ['#plain_text' => $this->authorLine($message)]],
        ['data' => ['#plain_text' => $this->formatCreated($message)]],
        ['data' => ['#plain_text' => $this->snippet($message)]],
      ],
    ];

    $rows[] = [
      'id' => 'ai-history-detail-' . $id,
      'class' => ['ai-history-detail'],
      'data-detail-for' => (string) $id,
      'data' => [
        [
          'colspan' => self::COLUMN_COUNT,
          'data' => $this->buildDetailItems($message),
        ],
      ],
    ];
  }

  /**
   * What kind of message a row holds, which its role alone does not say.
   *
   * Neuron's tool result message extends its user message, so a tool result
   * carries the user role while a tool produced it. Reading the role on its own
   * would credit the editor with what a tool answered.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   *
   * @return array
   *   The class name for the row and the label the Role column shows.
   */
  private function kindOf(ContentEntityInterface $message): array {
    $role = (string) $message->get('role')->value;

    return match (SessionConversation::decode($message, 'meta')['type'] ?? '') {
      'tool_call' => ['tool-call', 'assistant (tool call)'],
      'tool_call_result' => ['tool-result', 'tool result'],
      default => [$role, $role],
    };
  }

  /**
   * Formats the author column value for a message.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   *
   * @return string
   *   The display name with the uid for correlation, or an empty string for a
   *   message the model produced.
   */
  private function authorLine(ContentEntityInterface $message): string {
    $uid = (int) $message->get('uid')->target_id;
    if ($uid === 0) {
      return '';
    }
    $owner = $message->get('uid')->entity;

    return sprintf('%s (uid %d)', $owner ? $owner->getDisplayName() : $this->t('missing user'), $uid);
  }

  /**
   * Formats the created column value for a message.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   *
   * @return string
   *   The creation time in the date formatter's short format.
   */
  private function formatCreated(ContentEntityInterface $message): string {
    return $this->dateFormatter->format((int) $message->get('created')->value, 'short');
  }

  /**
   * Builds the single-line content snippet for the content column.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   *
   * @return string
   *   The whitespace-collapsed text, truncated to 80 characters.
   */
  private function snippet(ContentEntityInterface $message): string {
    $text = '';
    foreach (SessionConversation::decode($message, 'content') as $block) {
      $text .= (string) ($block['content'] ?? '');
    }
    $snippet = trim((string) preg_replace('/\s+/', ' ', $text));

    return mb_strlen($snippet) > 80 ? mb_substr($snippet, 0, 80) . '...' : $snippet;
  }

  /**
   * Builds the detail row items for a message.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   *
   * @return array
   *   The render array, each item present only when the message carries it.
   */
  private function buildDetailItems(ContentEntityInterface $message): array {
    $items = [];

    if ($author = $this->authorLine($message)) {
      $items['author'] = [
        '#type' => 'item',
        '#title' => $this->t('Author'),
        '#plain_text' => $author,
      ];
    }

    $items += $this->buildJsonItem($message, 'content', $this->t('Content'));
    $items += $this->buildJsonItem($message, 'meta', $this->t('Meta'));

    // Per-message token usage, only the counters the provider reported.
    $pairs = [];
    foreach (['input', 'output', 'total', 'reasoning', 'cached'] as $counter) {
      $value = $message->get('tokens_' . $counter)->value;
      if ($value !== NULL) {
        $pairs[] = $counter . ': ' . $value;
      }
    }
    if ($pairs !== []) {
      $items['token_usage'] = [
        '#type' => 'item',
        '#title' => $this->t('Token usage'),
        '#plain_text' => implode(', ', $pairs),
      ];
    }

    foreach (['message_id' => $this->t('Message ID'), 'finish_reason' => $this->t('Finish reason')] as $field => $label) {
      $value = $message->get($field)->value;
      if ($value !== NULL && $value !== '') {
        $items[$field] = [
          '#type' => 'item',
          '#title' => $label,
          '#plain_text' => (string) $value,
        ];
      }
    }

    return $items;
  }

  /**
   * Builds the item showing one stored JSON column, pretty printed.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $message
   *   The stored message.
   * @param string $field
   *   The column to render.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The item title.
   *
   * @return array
   *   The item keyed by the column, or nothing when the column is empty.
   */
  private function buildJsonItem(ContentEntityInterface $message, string $field, TranslatableMarkup $label): array {
    $value = $message->get($field)->value;
    if ($value === NULL || $value === '') {
      return [];
    }

    return [
      $field => [
        '#type' => 'item',
        '#title' => $label,
        'value' => [
          '#prefix' => '<pre>',
          '#plain_text' => $this->prettyPrint((string) $value),
          '#suffix' => '</pre>',
        ],
      ],
    ];
  }

  /**
   * Pretty-prints a stored JSON value, nested JSON strings included.
   *
   * A tool call keeps its inputs and its result as JSON strings inside the
   * metadata, so decoding those makes the detail row readable rather than one
   * escaped line.
   *
   * @param string $value
   *   The stored column value.
   *
   * @return string
   *   The pretty-printed JSON, or the value unchanged when it is not JSON.
   */
  private function prettyPrint(string $value): string {
    $decoded = json_decode($value);
    if (!is_array($decoded) && !is_object($decoded)) {
      return $value;
    }

    return json_encode(
      $this->decodeNestedJson($decoded),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) ?: $value;
  }

  /**
   * Recursively decodes JSON structures stored as strings inside a value.
   *
   * @param mixed $value
   *   The value to decode.
   *
   * @return mixed
   *   The value with every nested JSON structure decoded.
   */
  private function decodeNestedJson(mixed $value): mixed {
    if (is_string($value)) {
      // Decoding to objects rather than associative arrays keeps empty JSON
      // objects rendering as {} instead of [].
      $decoded = json_decode($value);

      return is_array($decoded) || is_object($decoded) ? $this->decodeNestedJson($decoded) : $value;
    }
    if (is_array($value)) {
      return array_map($this->decodeNestedJson(...), $value);
    }
    if (is_object($value)) {
      foreach (get_object_vars($value) as $key => $item) {
        $value->{$key} = $this->decodeNestedJson($item);
      }
    }

    return $value;
  }

  /**
   * Sums the tokens a list of rows reported.
   *
   * @param array $rows
   *   The stored messages.
   *
   * @return int
   *   The token total.
   */
  private function sumTokens(array $rows): int {
    $total = 0;
    foreach ($rows as $row) {
      $total += (int) $row->get('tokens_total')->value;
    }

    return $total;
  }

}
