<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Drupal\oe_ai_assistant\Exception\InvalidJsonFieldException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a session's conversation history as an expandable tree table.
 *
 * The whole conversation is loaded in one query and nested from the parent
 * pointers by the message storage handler (see loadTree()); this controller
 * flattens that tree depth first into one table. Each message contributes a
 * message row with the scannable columns and a detail row with the full
 * content, tool calls and telemetry. Expand/collapse is provided by the
 * session_history library; the server renders every row visible, so the
 * page stays fully readable without JavaScript.
 */
class AiConversationHistoryController extends ControllerBase {

  /**
   * The number of table columns, used to span detail rows across the table.
   */
  private const COLUMN_COUNT = 6;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface $messageStorage
   *   The conversation message storage handler.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    private readonly AiConversationMessageStorageInterface $messageStorage,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $storage = $container->get('entity_type.manager')->getStorage('ai_conversation_message');
    assert($storage instanceof AiConversationMessageStorageInterface);
    return new static($storage, $container->get('date.formatter'));
  }

  /**
   * Returns the page title for the history route.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The session whose conversation is displayed.
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
   *   The session whose conversation is displayed.
   *
   * @return array
   *   The render array: session totals followed by the message table, with
   *   sub-agent messages indented under their parent turn.
   */
  public function view(AiEditorialSessionInterface $ai_editorial_session): array {
    $tree = $this->messageStorage->loadTree($ai_editorial_session);

    $build = [];

    // Invalidate when the session changes or any message is written.
    CacheableMetadata::createFromObject($ai_editorial_session)
      ->addCacheContexts($this->messageStorage->getEntityType()->getListCacheContexts())
      ->addCacheTags($this->messageStorage->getEntityType()->getListCacheTags())
      ->applyTo($build);

    if ($tree === []) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('This session has no conversation messages yet.') . '</p>',
      ];
      return $build;
    }

    $build['totals'] = [
      '#type' => 'item',
      '#title' => $this->t('Session totals'),
      '#plain_text' => $this->t('@count messages, @tokens tokens', [
        '@count' => $this->countMessages($tree),
        '@tokens' => $this->sumTreeTokens($tree),
      ]),
    ];

    $rows = [];
    foreach ($tree as $branch) {
      $this->buildRows($branch, 0, $rows);
    }

    // The toolbar and table share one wrapper so the behavior can wire the
    // bulk buttons to their table. The toolbar is rendered hidden: only the
    // behavior reveals it, since without JavaScript the page is already
    // fully expanded and the buttons would do nothing.
    $build['history'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-history']],
      'toolbar' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-history-toolbar'],
          'hidden' => 'hidden',
        ],
        'expand_all' => $this->buildToolbarButton(
          'ai-history-expand-all',
          $this->t('Expand all'),
          $this->t('Collapse all'),
        ),
        'details_all' => $this->buildToolbarButton(
          'ai-history-details-all',
          $this->t('Show all details'),
          $this->t('Hide all details'),
        ),
      ],
      'messages' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Role'),
          $this->t('Agent'),
          $this->t('Author'),
          $this->t('Created'),
          $this->t('Tokens'),
          $this->t('Content'),
        ],
        '#rows' => $rows,
        '#sticky' => TRUE,
        '#attributes' => ['class' => ['ai-history-table']],
        '#attached' => ['library' => ['oe_ai_assistant/session_history']],
      ],
    ];

    return $build;
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
   * Appends the table rows for one message and its subtree.
   *
   * Each message contributes two rows: a message row with the scannable
   * columns and toggle buttons, and a detail row holding the full content,
   * tool calls and telemetry. Children follow their parent depth first. The
   * server renders every row visible and every toggle expanded; the
   * session_history behavior collapses the table to its default state.
   *
   * @param array $branch
   *   A branch as returned by loadTree(): a message and its children.
   * @param int $depth
   *   The nesting depth, zero for top-level turns.
   * @param array $rows
   *   The flat list of table rows built so far, modified by reference.
   * @param array $ancestry
   *   One boolean per ancestor level below the root: TRUE when that
   *   ancestor has a following sibling, so its vertical line continues.
   * @param bool $is_last
   *   Whether the message is its parent's last child. Unused for top-level
   *   turns, which carry no tree glyphs.
   */
  private function buildRows(array $branch, int $depth, array &$rows, array $ancestry = [], bool $is_last = TRUE): void {
    $message = $branch['message'];
    assert($message instanceof AiConversationMessageInterface);
    $id = (int) $message->id();
    $role = $message->getRole();

    // Box-drawing tree glyphs drawing the hierarchy: one continuation
    // segment per ancestor level, then a tee or corner connector for the
    // node itself, in the style of the tree command.
    $tree_prefix = '';
    if ($depth > 0) {
      foreach ($ancestry as $has_following_sibling) {
        $tree_prefix .= $has_following_sibling ? '│   ' : '    ';
      }
      $tree_prefix .= $is_last ? '└── ' : '├── ';
    }

    // The depth class drives the tinted background of each nesting level;
    // levels deeper than 3 reuse the darkest tint.
    $depth_class = 'ai-history-depth-' . min($depth, 3);

    $classes = ['ai-history-message', 'ai-history-role-' . $role, $depth_class];
    // Errors get an error tint on top of the admin theme's error coloring.
    if ($role === AiConversationMessageInterface::ROLE_ERROR) {
      $classes[] = 'color-error';
    }

    // The whole row is the detail toggle: focusable and announcing its
    // expanded state, so no dedicated inspect column is needed.
    $rows[] = [
      'id' => 'ai-history-message-' . $id,
      'class' => $classes,
      'data-message-id' => (string) $id,
      'data-parent-id' => (string) ($message->get('parent')->target_id ?? ''),
      'tabindex' => '0',
      'aria-expanded' => 'true',
      'aria-controls' => 'ai-history-detail-' . $id,
      'data' => [
        $this->buildRoleCell($branch, $role, $tree_prefix),
        ['data' => ['#plain_text' => (string) $message->get('agent_id')->value]],
        ['data' => ['#plain_text' => $this->authorLine($message)]],
        ['data' => ['#plain_text' => $this->formatCreated($message)]],
        ['data' => ['#plain_text' => $this->tokensCell($branch)]],
        ['data' => ['#plain_text' => $this->snippet($message)]],
      ],
    ];

    $rows[] = [
      'id' => 'ai-history-detail-' . $id,
      'class' => ['ai-history-detail', $depth_class],
      'data-detail-for' => (string) $id,
      // The exact depth rides along as a custom property so the stylesheet
      // can indent the detail content to match its message row.
      'style' => '--ai-history-depth: ' . $depth,
      'data' => [
        [
          'colspan' => self::COLUMN_COUNT,
          'data' => $this->buildDetailItems($message),
        ],
      ],
    ];

    // Children extend the ancestry with whether this node's own tree line
    // continues past them; top-level turns start a fresh ancestry.
    $child_ancestry = $depth === 0 ? [] : array_merge($ancestry, [!$is_last]);
    $last_index = count($branch['children']) - 1;
    foreach (array_values($branch['children']) as $i => $child) {
      $this->buildRows($child, $depth + 1, $rows, $child_ancestry, $i === $last_index);
    }
  }

  /**
   * Builds the role cell: tree glyphs, then the caret, then the role.
   *
   * The caret nests at the row's tree position, as in a file explorer. A
   * childless message gets an inert spacer of the same width instead, so
   * role labels align between siblings.
   *
   * @param array $branch
   *   The branch.
   * @param string $role
   *   The message role.
   * @param string $tree_prefix
   *   The box-drawing tree glyphs, empty for top-level turns.
   *
   * @return array
   *   The cell.
   */
  private function buildRoleCell(array $branch, string $role, string $tree_prefix): array {
    $cell = ['data' => []];
    if ($tree_prefix !== '') {
      // Decorative for screen readers: the hierarchy is already conveyed by
      // the data attributes and the caret buttons.
      $cell['data']['tree'] = [
        '#prefix' => '<span class="ai-history-tree" aria-hidden="true">',
        '#plain_text' => $tree_prefix,
        '#suffix' => '</span>',
      ];
    }
    if ($branch['children'] !== []) {
      $controls = [];
      foreach ($branch['children'] as $child) {
        $controls[] = 'ai-history-message-' . $child['message']->id();
      }
      $cell['data']['caret'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '',
        '#attributes' => [
          'type' => 'button',
          'class' => ['ai-history-toggle-children'],
          'aria-expanded' => 'true',
          'aria-controls' => implode(' ', $controls),
          'aria-label' => $this->t('Toggle sub-messages'),
        ],
      ];
    }
    else {
      $cell['data']['caret'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => ['class' => ['ai-history-caret-spacer']],
      ];
    }
    $cell['data']['role'] = ['#plain_text' => $role];
    return $cell;
  }

  /**
   * Formats the author column value for a message.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The message.
   *
   * @return string
   *   The display name with the uid for correlation, or an empty string for
   *   agent-produced messages without an owner.
   */
  private function authorLine(AiConversationMessageInterface $message): string {
    if ($message->getOwnerId() === NULL) {
      return '';
    }
    $owner = $message->getOwner();
    return sprintf('%s (uid %d)', $owner ? $owner->getDisplayName() : $this->t('missing user'), $message->getOwnerId());
  }

  /**
   * Formats the created column value for a message.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The message.
   *
   * @return string
   *   The creation time in the date formatter's short format, or an empty
   *   string when the field is empty.
   */
  private function formatCreated(AiConversationMessageInterface $message): string {
    $value = (string) $message->get('created')->value;
    if ($value === '') {
      return '';
    }
    // The datetime field stores an UTC string; the formatter converts the
    // timestamp back into the site timezone.
    try {
      $date = DrupalDateTime::createFromFormat(
        DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
        $value,
        DateTimeItemInterface::STORAGE_TIMEZONE,
      );
    }
    catch (\InvalidArgumentException | \UnexpectedValueException) {
      // Still show the raw stored value so the corruption can be inspected,
      // matching the invalid tool calls JSON fallback: a debug page must
      // not go down on the very data it exists to debug.
      return $value;
    }
    return $this->dateFormatter->format($date->getTimestamp(), 'short');
  }

  /**
   * Formats the tokens column value for a message.
   *
   * @param array $branch
   *   The branch.
   *
   * @return string
   *   The subtree total for a turn with children, the message's own total
   *   for a leaf, or an empty string when nothing was recorded.
   */
  private function tokensCell(array $branch): string {
    $tokens = $this->sumBranchTokens($branch);
    if ($branch['children'] !== []) {
      return 'subtree: ' . $tokens;
    }
    return $tokens > 0 ? (string) $tokens : '';
  }

  /**
   * Builds the single-line content snippet for the content column.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The message.
   *
   * @return string
   *   The whitespace-collapsed content, truncated to 80 characters.
   */
  private function snippet(AiConversationMessageInterface $message): string {
    $snippet = trim((string) preg_replace('/\s+/', ' ', (string) $message->get('content')->value));
    return mb_strlen($snippet) > 80 ? mb_substr($snippet, 0, 80) . '...' : $snippet;
  }

  /**
   * Builds the detail row items for a message.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The message.
   *
   * @return array
   *   The render array with author, content, tool calls, token usage and
   *   telemetry items, each present only when the message carries a value.
   */
  private function buildDetailItems(AiConversationMessageInterface $message): array {
    $items = [];

    // Author of user messages, with the uid for correlation.
    if ($message->getOwnerId() !== NULL) {
      $items['author'] = [
        '#type' => 'item',
        '#title' => $this->t('Author'),
        '#plain_text' => $this->authorLine($message),
      ];
    }

    $content = (string) $message->get('content')->value;
    if ($content !== '') {
      $items['content'] = [
        '#type' => 'item',
        '#title' => $this->t('Content'),
        'value' => [
          '#prefix' => '<pre>',
          '#plain_text' => $this->prettyPrintJsonContent($content),
          '#suffix' => '</pre>',
        ],
      ];
    }

    if ($tool_calls = $this->buildToolCalls($message)) {
      $items['tool_calls'] = $tool_calls;
    }

    // Per-message token usage, only the counters the provider reported.
    $usage = array_filter($message->getTokenUsage(), static fn (?int $value): bool => $value !== NULL);
    if ($usage !== []) {
      $pairs = [];
      foreach ($usage as $key => $value) {
        $pairs[] = $key . ': ' . $value;
      }
      $items['token_usage'] = [
        '#type' => 'item',
        '#title' => $this->t('Token usage'),
        '#plain_text' => implode(', ', $pairs),
      ];
    }

    // Remaining scalar telemetry fields, shown only when set.
    $telemetry = [
      'provider' => $this->t('Provider'),
      'model' => $this->t('Model'),
      'latency_ms' => $this->t('Latency (ms)'),
      'finish_reason' => $this->t('Finish reason'),
    ];
    foreach ($telemetry as $field => $label) {
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
   * Pretty-prints message content when it is a JSON structure.
   *
   * Sub-agents with structured output store raw single-line JSON strings as
   * message content; reformatting them makes the detail row readable. JSON
   * scalars and non-JSON text are returned unchanged.
   *
   * @param string $content
   *   The stored message content.
   *
   * @return string
   *   The pretty-printed JSON, or the original content.
   */
  private function prettyPrintJsonContent(string $content): string {
    // Decoding to objects rather than associative arrays keeps empty JSON
    // objects rendering as {} instead of [].
    $decoded = json_decode($content);
    if (!is_array($decoded) && !is_object($decoded)) {
      return $content;
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $content;
  }

  /**
   * Builds the tool calls item for a message.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The message.
   *
   * @return array|null
   *   The render array, or NULL when the message has no tool calls.
   */
  private function buildToolCalls(AiConversationMessageInterface $message): ?array {
    try {
      $tool_calls = $message->getToolCalls();
    }
    catch (InvalidJsonFieldException) {
      // Still show the raw stored value so the corruption can be inspected.
      return [
        '#type' => 'item',
        '#title' => $this->t('Tool calls (invalid JSON)'),
        'value' => [
          '#prefix' => '<pre>',
          '#plain_text' => (string) $message->get('tool_calls')->value,
          '#suffix' => '</pre>',
        ],
      ];
    }
    if ($tool_calls === []) {
      return NULL;
    }
    return [
      '#type' => 'item',
      '#title' => $this->t('Tool calls'),
      'value' => [
        '#prefix' => '<pre>',
        '#plain_text' => json_encode($this->decodeNestedJson($tool_calls), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        '#suffix' => '</pre>',
      ],
    ];
  }

  /**
   * Recursively decodes JSON structures stored as strings inside a value.
   *
   * Providers store tool call arguments (and sometimes results) as
   * JSON-encoded strings inside the tool calls structure; decoding them lets
   * the pretty printer indent them instead of emitting one escaped line.
   * Strings that do not hold a JSON structure are returned unchanged.
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
      if (is_array($decoded) || is_object($decoded)) {
        return $this->decodeNestedJson($decoded);
      }
      return $value;
    }
    if (is_array($value)) {
      return array_map($this->decodeNestedJson(...), $value);
    }
    if (is_object($value)) {
      foreach (get_object_vars($value) as $key => $item) {
        $value->{$key} = $this->decodeNestedJson($item);
      }
      return $value;
    }
    return $value;
  }

  /**
   * Sums the token usage of a node's whole subtree.
   *
   * @param array $branch
   *   The branch.
   *
   * @return int
   *   The token total for the node and all of its descendants.
   */
  private function sumBranchTokens(array $branch): int {
    $message = $branch['message'];
    assert($message instanceof AiConversationMessageInterface);
    $usage = $message->getTokenUsage();
    // Prefer the provider-reported total; fall back to input + output for
    // rows where only the split counters were recorded.
    $total = $usage['total'] ?? (($usage['input'] ?? 0) + ($usage['output'] ?? 0));
    return $total + $this->sumTreeTokens($branch['children']);
  }

  /**
   * Sums the token usage of a list of subtrees.
   *
   * @param array $branches
   *   A list of branches.
   *
   * @return int
   *   The combined token total.
   */
  private function sumTreeTokens(array $branches): int {
    $total = 0;
    foreach ($branches as $branch) {
      $total += $this->sumBranchTokens($branch);
    }
    return $total;
  }

  /**
   * Counts the messages in a list of subtrees.
   *
   * @param array $branches
   *   A list of branches.
   *
   * @return int
   *   The number of messages, descendants included.
   */
  private function countMessages(array $branches): int {
    $count = 0;
    foreach ($branches as $branch) {
      $count += 1 + $this->countMessages($branch['children']);
    }
    return $count;
  }

}
