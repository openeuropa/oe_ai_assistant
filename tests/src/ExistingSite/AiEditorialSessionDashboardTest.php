<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the AI editorial session dashboard.
 */
class AiEditorialSessionDashboardTest extends ExistingSiteBase {

  /**
   * Tests the dashboard renders the expected listing and operations.
   */
  public function testDashboard(): void {
    $user = $this->createUser([
      'administer ai editorial sessions',
    ]);
    $this->loginUser($user);

    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->create([
        'bundle' => 'drafting',
        'uid' => $user->id(),
        'content_type' => 'oe_news',
        'template_id' => 'landing_page',
      ]);
    $session->save();

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI editorial sessions');
    $this->assertSession()->pageTextContains('Add new session');
    $this->assertSession()->pageTextContains('Label');
    $this->assertSession()->pageTextContains('Bundle');
    $this->assertSession()->pageTextContains('Content type');
    $this->assertSession()->pageTextContains('Template');
    $this->assertSession()->pageTextContains('Node');
    $this->assertSession()->pageTextContains('Owner');
    $this->assertSession()->pageTextContains('Status');
    $this->assertSession()->pageTextContains('Created');
    $this->assertSession()->pageTextContains('Changed');
    $this->assertSession()->pageTextContains('oe_news');
    $this->assertSession()->pageTextContains('landing_page');
    $this->assertSession()->pageTextContains('active');
    $this->assertSession()->linkExists('Continue');
    $this->assertSession()->linkExists('Delete');
  }

  /**
   * Tests the admin parent routes for content and configuration.
   */
  public function testAdminStructure(): void {
    $user = $this->createUser([
      'administer ai editorial sessions',
      'access administration pages',
      'access content overview',
    ]);
    $this->loginUser($user);

    $this->drupalGet(Url::fromRoute('system.admin_content'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('AI Editorial Assistant');

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.admin_config'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Editorial Assistant');
  }

  /**
   * Tests creating a drafting session from the add flow.
   */
  public function testAddSessionFlow(): void {
    $user = $this->createUser([
      'administer ai editorial sessions',
      'create oe_news content',
    ]);
    $this->loginUser($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.add_page'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('content_type');
    $this->assertSession()->fieldExists('template_id');

    $this->submitForm([
      'content_type' => 'oe_news',
      'template_id' => 'landing_page',
    ], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Session placeholder page.');
    $this->assertSession()->pageTextContains('Content type: oe_news');
    $this->assertSession()->pageTextContains('Status: active');

    $sessions = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->loadByProperties([
        'uid' => $user->id(),
        'content_type' => 'oe_news',
        'template_id' => 'landing_page',
      ]);

    $session = reset($sessions);
    $this->assertNotFalse($session);
    $this->assertSame('drafting', $session->bundle());
  }

  /**
   * Logs in a user via the login form.
   */
  protected function loginUser(UserInterface $account): void {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet(Url::fromRoute('user.login'));
    $this->submitForm([
      'name' => $account->getAccountName(),
      'pass' => $account->passRaw,
    ], 'Log in');

    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
  }

}
