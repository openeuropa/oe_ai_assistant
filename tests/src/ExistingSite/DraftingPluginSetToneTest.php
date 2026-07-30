<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin set-tone action.
 */
class DraftingPluginSetToneTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('module_installer')->install(['oe_ai_assistant_test']);
  }

  /**
   * Tests that a valid tone selection is saved on the session.
   */
  public function testSetToneAcceptsValidTone(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);
    $session = $this->createSession($user);
    $toneId = $this->getTermIdByName('oe_ai_tone', 'Formal');

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $toneId,
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['status' => 'ok'], $result['body']);

    // The selected tone is stored on the session entity.
    $storage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $storage->resetCache([$session->id()]);
    $saved = $storage->load($session->id());
    $this->assertSame($toneId, (string) $saved->get('tone')->target_id);
  }

  /**
   * Tests that a missing tone ID is rejected by request validation.
   */
  public function testSetToneRejectsMissingToneId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => '1',
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('bad_request', $result['body']['code']);
    $this->assertStringContainsString('toneId', $result['body']['message']);
  }

  /**
   * Tests that unknown term IDs are rejected.
   */
  public function testSetToneRejectsInvalidTermId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => '1',
      'toneId' => '999999',
    ]);

    $this->assertSame(400, $result['status']);
    $this->assertSame('invalid_context', $result['body']['code']);
    $this->assertStringContainsString('oe_ai_tone', $result['body']['message']);
  }

  /**
   * Tests that tone IDs from the wrong vocabulary are rejected.
   */
  public function testSetToneRejectsWrongVocabularyId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $otherTerm = Term::create([
      'vid' => 'news_tags',
      'name' => 'Wrong vocabulary',
    ]);
    $otherTerm->save();

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => '1',
      'toneId' => (string) $otherTerm->id(),
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
   * Creates a content creation session owned by the given user.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The session owner.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The saved session.
   */
  protected function createSession(UserInterface $owner): AiEditorialSessionInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $owner->id(),
        'content_type' => 'oe_news',
      ]);
    $session->save();
    $this->markEntityForCleanup($session);
    return $session;
  }

}
