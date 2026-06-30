<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Tests the AI conversation message overview dashboard and add form.
 *
 * @group oe_ai_assistant
 */
class AiConversationMessageDashboardTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['oe_ai_assistant'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The collection route path.
   */
  private const COLLECTION = '/admin/config/ai-editorial/messages';

  /**
   * Creates a saved conversation message.
   *
   * @param int $host_id
   *   The host entity id.
   * @param string $role
   *   The message role.
   * @param array $tokens
   *   The token usage array.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  private function createMessage(int $host_id, string $role, array $tokens = []): AiConversationMessageInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message */
    $message = AiConversationMessage::create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => $host_id,
      'role' => $role,
    ]);
    if ($tokens) {
      $message->setTokenUsage($tokens);
    }
    $message->save();
    return $message;
  }

  /**
   * Tests that the overview is gated by the overview permission.
   */
  public function testOverviewAccess(): void {
    // Anonymous is denied.
    $this->drupalGet(self::COLLECTION);
    $this->assertSession()->statusCodeEquals(403);

    // An authenticated user without permission is denied.
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet(self::COLLECTION);
    $this->assertSession()->statusCodeEquals(403);

    // The overview permission grants access.
    $this->drupalLogin($this->drupalCreateUser(['access ai conversation message overview']));
    $this->drupalGet(self::COLLECTION);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests the token totals row and that filters constrain it.
   */
  public function testTotalsRowAndFilter(): void {
    $this->createMessage(42, 'user', ['input' => 10, 'output' => 5, 'total' => 15]);
    $this->createMessage(42, 'assistant', ['input' => 20, 'output' => 8, 'total' => 28]);
    $this->createMessage(99, 'user', ['input' => 100, 'output' => 100, 'total' => 200]);

    $this->drupalLogin($this->drupalCreateUser(['access ai conversation message overview']));

    // Unfiltered: total tokens sum across all three is 243.
    $this->drupalGet(self::COLLECTION);
    $this->assertSession()->pageTextContains('Totals (filtered)');
    $this->assertSession()->pageTextContains('243');

    // Filtered to host 42: total tokens sum is 43, and 243 is gone.
    $this->drupalGet(self::COLLECTION, ['query' => ['host_entity_id' => 42]]);
    $this->assertSession()->pageTextContains('43');
    $this->assertSession()->pageTextNotContains('243');
  }

  /**
   * Tests that creating a message requires the create permission.
   */
  public function testCreateRequiresPermission(): void {
    // Overview only: the add action is not shown.
    $this->drupalLogin($this->drupalCreateUser(['access ai conversation message overview']));
    $this->drupalGet(self::COLLECTION);
    $this->assertSession()->linkNotExists('Add conversation message');

    // With the create permission the add form works and persists a message.
    $this->drupalLogin($this->drupalCreateUser([
      'access ai conversation message overview',
      'create ai conversation message',
    ]));
    $this->drupalGet(self::COLLECTION);
    $this->assertSession()->linkExists('Add conversation message');

    $this->drupalGet('/admin/config/ai-editorial/messages/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => 7,
      'role' => 'user',
      'content' => 'Hello from the form.',
    ], 'Save');
    $this->assertSession()->pageTextContains('Saved conversation message');

    $ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_conversation_message')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('host_entity_id', 7)
      ->execute();
    $this->assertCount(1, $ids);
  }

  /**
   * Tests that invalid JSON in the add form is rejected with a form error.
   */
  public function testAddFormRejectsInvalidJson(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access ai conversation message overview',
      'create ai conversation message',
    ]));
    $this->drupalGet('/admin/config/ai-editorial/messages/add');
    $this->submitForm([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => 7,
      'role' => 'assistant',
      'tool_calls' => 'not valid json',
    ], 'Save');
    $this->assertSession()->pageTextContains('is not valid JSON');
  }

}
