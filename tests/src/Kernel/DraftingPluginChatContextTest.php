<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that the drafting chat action evidences the received template id.
 */
#[Group('oe_ai_assistant')]
class DraftingPluginChatContextTest extends AiEditorialSessionKernelTestBase {

  /**
   * The log records collected from the module's logger channel.
   *
   * @var array<int, array{level: mixed, message: string, context: array}>
   */
  private array $logRecords = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $records = &$this->logRecords;
    $collector = new class ($records) extends AbstractLogger {

      public function __construct(private array &$records) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [
          'level' => $level,
          'message' => (string) $message,
          'context' => $context,
        ];
      }

    };
    $this->container->get('logger.channel.oe_ai_assistant')
      ->addLogger($collector);
  }

  /**
   * Invokes the drafting chat action, ignoring downstream AI failures.
   *
   * Creates an oe_news editorial session owned by the acting user and merges
   * its id into the body, so the session-derived context is built.
   */
  private function chat(array $body): void {
    $owner = $this->createUser(['use oe ai assistant', 'view_update own sessions']);
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $body += ['sessionId' => (string) $session->id()];

    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode($body));
    try {
      $plugin->executeAction('chat', $request);
    }
    catch (\Throwable) {
      // No AI provider is configured in this test; the chat pipeline fails
      // after the context is built, which is all this test needs. This
      // relies on chat() logging the template right after buildContext(),
      // before any call that can throw.
    }
  }

  /**
   * Returns the collected messages with their context placeholders applied.
   */
  private function loggedMessages(): array {
    return array_map(
      static fn (array $record): string => strtr($record['message'], $record['context']),
      $this->logRecords,
    );
  }

  /**
   * A non-empty template id on the chat body is logged as evidence.
   */
  public function testChatLogsReceivedTemplateId(): void {
    $this->chat([
      'message' => 'Draft a news item.',
      'template' => 'news_default',
    ]);

    $messages = $this->loggedMessages();
    $matches = array_filter(
      $messages,
      static fn (string $message): bool =>
        str_contains($message, 'news_default')
        && str_contains($message, 'oe_news'),
    );

    $this->assertNotEmpty($matches, 'The received template id is logged. Got: ' . implode(' | ', $messages));
  }

  /**
   * An absent template produces no template evidence log.
   */
  public function testChatWithoutTemplateLogsNothing(): void {
    $this->chat([
      'message' => 'Draft a news item.',
    ]);

    $matches = array_filter(
      $this->loggedMessages(),
      static fn (string $message): bool => str_contains($message, 'template'),
    );

    $this->assertSame([], array_values($matches));
  }

  /**
   * The forwardedProps template takes precedence over the body template.
   */
  public function testForwardedPropsTemplateWinsOverBodyTemplate(): void {
    $this->chat([
      'message' => 'Draft a news item.',
      'template' => 'from_body',
      'forwardedProps' => ['template' => 'from_props'],
    ]);

    $messages = $this->loggedMessages();
    $matches = array_filter(
      $messages,
      static fn (string $message): bool => str_contains($message, 'from_props'),
    );

    $this->assertNotEmpty($matches, 'The forwardedProps template id is logged. Got: ' . implode(' | ', $messages));
    $this->assertSame([], array_values(array_filter(
      $messages,
      static fn (string $message): bool => str_contains($message, 'from_body'),
    )));
  }

  /**
   * A non-string template value is ignored instead of being logged.
   */
  public function testChatIgnoresNonStringTemplate(): void {
    $this->chat([
      'message' => 'Draft a news item.',
      'template' => ['nested' => 'value'],
    ]);

    $matches = array_filter(
      $this->loggedMessages(),
      static fn (string $message): bool => str_contains($message, 'template'),
    );

    $this->assertSame([], array_values($matches));
  }

}
