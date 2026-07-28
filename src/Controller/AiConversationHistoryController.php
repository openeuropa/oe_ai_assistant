<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Drupal\oe_ai_assistant\Exception\InvalidJsonFieldException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a session's conversation history as a collapsible tree.
 *
 * The whole conversation is loaded in one query and nested from the parent
 * pointers by the message storage handler (see loadTree()); this controller
 * only maps that tree onto nested core details elements (native HTML5
 * details/summary), so the page needs no custom JS or CSS.
 */
class AiConversationHistoryController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface $messageStorage
   *   The conversation message storage handler.
   */
  public function __construct(
    private readonly AiConversationMessageStorageInterface $messageStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $storage = $container->get('entity_type.manager')->getStorage('ai_conversation_message');
    assert($storage instanceof AiConversationMessageStorageInterface);
    return new static($storage);
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
   *   The render array: session totals followed by one details element per
   *   top-level turn, with sub-agent messages nested inside.
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

    foreach ($tree as $branch) {
      $build['turns'][] = $this->buildBranch($branch);
    }

    return $build;
  }

  /**
   * Builds the details element for one message and its children.
   *
   * @param array $branch
   *   A branch as returned by loadTree(): a message and its children.
   *
   * @return array
   *   The render array for the branch.
   */
  private function buildBranch(array $branch): array {
    $message = $branch['message'];
    assert($message instanceof AiConversationMessageInterface);
    $role = $message->getRole();

    $build = [
      '#type' => 'details',
      // Passed as a render array, not a string: template_preprocess_details()
      // wraps a plain string in '#markup', which only XSS-filters it against
      // the admin tag list. The summary carries stored message text, so it
      // must be escaped rather than filtered.
      '#title' => ['#plain_text' => $this->buildSummaryLine($branch)],
      '#attributes' => ['class' => ['ai-history-message', 'ai-history-role-' . $role]],
    ];

    // Errors stand out through core's message classes; no custom CSS needed.
    if ($role === AiConversationMessageInterface::ROLE_ERROR) {
      $build['#attributes']['class'][] = 'messages';
      $build['#attributes']['class'][] = 'messages--error';
    }

    // Author of user messages, with the uid for correlation.
    if ($message->getOwnerId() !== NULL) {
      $owner = $message->getOwner();
      $build['author'] = [
        '#type' => 'item',
        '#title' => $this->t('Author'),
        '#plain_text' => sprintf('%s (uid %d)', $owner ? $owner->getDisplayName() : $this->t('missing user'), $message->getOwnerId()),
      ];
    }

    $content = (string) $message->get('content')->value;
    if ($content !== '') {
      $build['content'] = [
        '#type' => 'item',
        '#title' => $this->t('Content'),
        'value' => [
          '#prefix' => '<pre>',
          '#plain_text' => $content,
          '#suffix' => '</pre>',
        ],
      ];
    }

    if ($tool_calls = $this->buildToolCalls($message)) {
      $build['tool_calls'] = $tool_calls;
    }

    // Per-message token usage, only the counters the provider reported.
    $usage = array_filter($message->getTokenUsage(), static fn (?int $value): bool => $value !== NULL);
    if ($usage !== []) {
      $pairs = [];
      foreach ($usage as $key => $value) {
        $pairs[] = $key . ': ' . $value;
      }
      $build['token_usage'] = [
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
        $build[$field] = [
          '#type' => 'item',
          '#title' => $label,
          '#plain_text' => (string) $value,
        ];
      }
    }

    foreach ($branch['children'] as $child) {
      $build['children'][] = $this->buildBranch($child);
    }

    return $build;
  }

  /**
   * Builds the one-line summary shown on a collapsed details element.
   *
   * @param array $branch
   *   The branch.
   *
   * @return string
   *   Role, agent or author, timestamp, token count and a content snippet.
   */
  private function buildSummaryLine(array $branch): string {
    $message = $branch['message'];
    assert($message instanceof AiConversationMessageInterface);

    $parts = [$message->getRole()];

    if ($agent = $message->get('agent_id')->value) {
      $parts[] = $agent;
    }
    if ($message->getOwnerId() !== NULL) {
      $owner = $message->getOwner();
      $parts[] = $owner ? (string) $owner->getDisplayName() : 'uid ' . $message->getOwnerId();
    }
    if ($created = $message->get('created')->value) {
      $parts[] = $created;
    }

    // A turn with children shows its subtree total; a leaf its own tokens.
    $tokens = $this->sumBranchTokens($branch);
    if ($branch['children'] !== []) {
      $parts[] = 'subtree: ' . $tokens . ' tokens';
    }
    elseif ($tokens > 0) {
      $parts[] = $tokens . ' tokens';
    }

    // A short single-line content snippet so turns are scannable while closed.
    $snippet = trim((string) preg_replace('/\s+/', ' ', (string) $message->get('content')->value));
    if ($snippet !== '') {
      $parts[] = mb_strlen($snippet) > 80 ? mb_substr($snippet, 0, 80) . '...' : $snippet;
    }

    return implode(' | ', $parts);
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
        '#plain_text' => json_encode($tool_calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        '#suffix' => '</pre>',
      ],
    ];
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
