<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\taxonomy\Entity\Term;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin save-tone action.
 */
class DraftingPluginSaveToneTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('module_installer')->install(['dblog', 'oe_ai_assistant_test']);
    \Drupal::database()->delete('watchdog')
      ->condition('type', 'oe_ai_assistant')
      ->execute();
  }

  /**
   * Tests that valid tone selections are accepted.
   */
  public function testSaveToneAcceptsValidTone(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save-tone', [
      'context' => [
        'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
      ],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['status' => 'ok'], $result['body']);
    $this->assertAcceptedSelectionWasLogged();
  }

  /**
   * Tests that a missing tone ID is rejected by request validation.
   */
  public function testSaveToneRejectsMissingToneId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save-tone', [
      'context' => [
        'unused' => 'value',
      ],
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('bad_request', $result['body']['code']);
    $this->assertStringContainsString('toneId', $result['body']['message']);
  }

  /**
   * Tests that unknown term IDs are rejected.
   */
  public function testSaveToneRejectsInvalidTermId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save-tone', [
      'context' => [
        'toneId' => '999999',
      ],
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('invalid_context', $result['body']['code']);
    $this->assertStringContainsString('oe_ai_tone', $result['body']['message']);
  }

  /**
   * Tests that tone IDs from the wrong vocabulary are rejected.
   */
  public function testSaveToneRejectsWrongVocabularyId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $otherTerm = Term::create([
      'vid' => 'news_tags',
      'name' => 'Wrong vocabulary',
    ]);
    $otherTerm->save();

    $result = $this->httpPost('/api/ai/plugins/drafting/save-tone', [
      'context' => [
        'toneId' => (string) $otherTerm->id(),
      ],

    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('invalid_context', $result['body']['code']);
    $this->assertStringContainsString('oe_ai_tone', $result['body']['message']);
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

  /**
   * Asserts the accepted tone selection was logged.
   */
  protected function assertAcceptedSelectionWasLogged(): void {
    $count = \Drupal::database()
      ->select('watchdog', 'w')
      ->condition('type', 'oe_ai_assistant')
      ->condition('message', 'OEL-4851 drafting tone selection accepted%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertGreaterThan(0, (int) $count);
  }

}
