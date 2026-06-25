<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin save-session action.
 */
class DraftingPluginSaveSessionTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('module_installer')->install(['oe_ai_assistant_test']);
  }

  /**
   * Tests that valid audience and tone selections are accepted.
   */
  public function testSaveSessionAcceptsValidContext(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save-session', [
      'context' => [
        'audienceId' => $this->getTermIdByName('oe_ai_target_audience', 'Business and industry'),
        'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
      ],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['status' => 'ok'], $result['body']);
  }

  /**
   * Tests that missing context values are rejected by request validation.
   */
  public function testSaveSessionRejectsMissingRequiredContextIds(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save-session', [
      'context' => [
        'audienceId' => $this->getTermIdByName('oe_ai_target_audience', 'Business and industry'),
      ],
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('bad_request', $result['body']['code']);
    $this->assertStringContainsString('toneId', $result['body']['message']);
  }

  /**
   * Tests that unknown term IDs are rejected.
   */
  public function testSaveSessionRejectsInvalidContextTermId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save-session', [
      'context' => [
        'audienceId' => '999999',
        'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
      ],
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('invalid_context', $result['body']['code']);
    $this->assertStringContainsString('oe_ai_target_audience', $result['body']['message']);
  }

  /**
   * Tests that context IDs from the wrong vocabulary are rejected.
   */
  public function testSaveSessionRejectsWrongContextVocabularyId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $toneId = $this->getTermIdByName('oe_ai_tone', 'Formal');
    $result = $this->httpPost('/api/ai/plugins/drafting/save-session', [
      'context' => [
        'audienceId' => $toneId,
        'toneId' => $toneId,
      ],
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('invalid_context', $result['body']['code']);
    $this->assertStringContainsString('oe_ai_target_audience', $result['body']['message']);
  }

  /**
   * Logs in a user via the login form.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account to log in.
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

  /**
   * Sends a POST request with JSON body using the BrowserKit client.
   *
   * @param string $url
   *   The URL to post to.
   * @param array $body
   *   The request body to encode as JSON.
   *
   * @return array
   *   An array with 'status' and 'body' keys.
   */
  protected function httpPost(string $url, array $body): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();

    $client->request(
      'POST',
      $this->baseUrl . $url,
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($body),
    );

    $response = $client->getResponse();

    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode($response->getContent(), TRUE),
    ];
  }

  /**
   * Returns the taxonomy term ID for a fixture term.
   */
  protected function getTermIdByName(string $vid, string $name): string {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vid,
        'name' => $name,
      ]);

    $term = reset($terms);
    if (!$term) {
      $this->fail(sprintf('Term "%s" was not found in "%s".', $name, $vid));
    }

    return (string) $term->id();
  }

}
