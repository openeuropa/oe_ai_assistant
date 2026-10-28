<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\ai_neuron\Entity\NeuronMessageInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Neuron-backed conversation history of a session.
 *
 * Separate from AiConversationHistoryController, which keeps reading the
 * old ai_conversation_message entity until OEL-5072 removes it. This one
 * is empty at first: nothing writes to the Neuron store until then.
 *
 * @todo Remove the note above when the OEL-5066 epic is complete.
 */
final class SessionConversationHistoryController extends ControllerBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns the page title for the history route.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The session whose conversations this page shows.
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
   *   The session whose conversations this page shows.
   *
   * @return array
   *   The render array: session totals followed by one table per thread.
   */
  public function view(AiEditorialSessionInterface $ai_editorial_session): array {
    $threads = $ai_editorial_session->conversationThreads();

    $build = [];

    // Invalidate when the session changes or the store writes a message.
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

    $messages = 0;
    $tokens = 0;
    foreach ($threads as $thread) {
      $messages += count($thread);
      $tokens += $this->sumTokens($thread);
    }
    $build['totals'] = [
      '#type' => 'item',
      '#title' => $this->t('Session totals'),
      '#plain_text' => $this->t('@messages messages in @threads conversations, @tokens tokens', [
        '@messages' => $messages,
        '@threads' => count($threads),
        '@tokens' => $tokens,
      ]),
    ];

    $show = $this->t('Show all details');
    $build['history'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-history']],
      'toolbar' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-history-toolbar'],
          // Useless without JavaScript, which is what reveals it.
          'hidden' => 'hidden',
        ],
        'details_all' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $show,
          '#attributes' => [
            'type' => 'button',
            'class' => ['button', 'button--small', 'ai-history-details-all'],
            // The button starts collapsed. The behavior swaps its label from
            // these attributes as it toggles.
            'aria-expanded' => 'false',
            'data-label-show' => $show,
            'data-label-hide' => $this->t('Hide all details'),
          ],
        ],
      ],
      '#attached' => ['library' => ['oe_ai_assistant/session_history']],
    ];

    foreach ($threads as $threadId => $thread) {
      $build['history'][$threadId] = $this->buildThread((string) $threadId, $thread);
    }

    return $build;
  }

  /**
   * Builds the heading and the table of one thread, oldest message first.
   */
  private function buildThread(string $threadId, array $messages): array {
    $tableRows = [];
    foreach ($messages as $message) {
      array_push($tableRows, ...$this->buildRows($message));
    }

    return [
      '#type' => 'container',
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('@thread (@count messages, @tokens tokens)', [
          '@thread' => $threadId,
          '@count' => count($messages),
          '@tokens' => $this->sumTokens($messages),
        ]),
      ],
      'table' => [
        '#type' => 'table',
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
      ],
    ];
  }

  /**
   * Builds the message row and the detail row of one message.
   *
   * The server renders both rows visible. The session_history behavior then
   * collapses the details to their default state.
   */
  private function buildRows(NeuronMessageInterface $message): array {
    $id = (int) $message->id();
    [$kind, $label] = $this->kindOf($message);

    return [
      // The whole row is the detail toggle. It takes focus and announces its
      // expanded state, so the table needs no inspect column.
      [
        'id' => 'ai-history-message-' . $id,
        'class' => ['ai-history-message', 'ai-history-role-' . $kind],
        'data-message-id' => (string) $id,
        // The behavior hides every row that names a parent, so a row of its
        // own has to say it has none.
        'data-parent-id' => '',
        'tabindex' => '0',
        'aria-expanded' => 'true',
        'aria-controls' => 'ai-history-detail-' . $id,
        'data' => [
          ['data' => ['#plain_text' => $label]],
          ['data' => ['#plain_text' => (string) $message->get('turn')->value]],
          ['data' => ['#plain_text' => (string) $message->get('agent_id')->value]],
          ['data' => ['#plain_text' => $this->authorLine($message)]],
          ['data' => ['#plain_text' => $this->dateFormatter->format((int) $message->get('created')->value, 'short')]],
          ['data' => ['#plain_text' => $this->snippet($message)]],
        ],
      ],
      [
        'id' => 'ai-history-detail-' . $id,
        'class' => ['ai-history-detail'],
        'data-detail-for' => (string) $id,
        'data' => [
          [
            'colspan' => 6,
            'data' => $this->buildDetailItems($message),
          ],
        ],
      ],
    ];
  }

  /**
   * What kind of message a row holds, which its role alone does not say.
   *
   * Neuron's tool result message extends its user message, so role alone
   * would credit the editor with what a tool answered.
   */
  private function kindOf(NeuronMessageInterface $message): array {
    $role = (string) $message->get('role')->value;

    return match ($message->getMeta()['type'] ?? '') {
      'tool_call' => ['tool-call', $this->t('assistant (tool call)')],
      'tool_call_result' => ['tool-result', $this->t('tool result')],
      default => [$role, $role],
    };
  }

  /**
   * The author of a message, empty for a message the model produced.
   */
  private function authorLine(NeuronMessageInterface $message): TranslatableMarkup|string {
    $uid = $message->getOwnerId();
    if ($uid === NULL) {
      return '';
    }
    $owner = $message->getOwner();

    return $this->t('@name (uid @uid)', [
      '@name' => $owner ? $owner->getDisplayName() : $this->t('missing user'),
      '@uid' => $uid,
    ]);
  }

  /**
   * The content of a message as one line, truncated to 80 characters.
   */
  private function snippet(NeuronMessageInterface $message): string {
    $text = '';
    foreach ($message->getContent() ?? [] as $block) {
      $text .= (string) ($block['content'] ?? '');
    }
    $snippet = trim((string) preg_replace('/\s+/', ' ', $text));

    return mb_strlen($snippet) > 80 ? mb_substr($snippet, 0, 80) . '...' : $snippet;
  }

  /**
   * Builds the detail row items, each present only when the message has it.
   */
  private function buildDetailItems(NeuronMessageInterface $message): array {
    $items = $this->buildJsonItem($message, 'content', $this->t('Content'));
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
   * Builds the item that shows one stored JSON column, pretty printed.
   *
   * The key of the item is the column name, so a caller adds it to the items it
   * holds. A column that holds no value gives nothing.
   */
  private function buildJsonItem(NeuronMessageInterface $message, string $field, TranslatableMarkup $label): array {
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
   * metadata. This method decodes those, so the detail row reads as JSON and
   * not as one escaped line. A value that is not JSON stays unchanged.
   */
  private function prettyPrint(string $value): string {
    $decoded = $this->decodeNestedJson($value);
    if (is_string($decoded)) {
      return $value;
    }

    return json_encode(
      $decoded,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) ?: $value;
  }

  /**
   * Recursively decodes JSON structures stored as strings inside a value.
   */
  private function decodeNestedJson(mixed $value): mixed {
    if (is_string($value)) {
      // This decodes to objects, not to associative arrays. An empty JSON
      // object then renders as {} and not as [].
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
   * Sums the tokens a list of stored messages reported.
   */
  private function sumTokens(array $messages): int {
    $total = 0;
    foreach ($messages as $message) {
      $total += (int) $message->get('tokens_total')->value;
    }

    return $total;
  }

}
