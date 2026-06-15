<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use Drupal\oe_ai_assistant\Store\DraftingSelectionStoreInterface;
use Drupal\user\UserInterface;

/**
 * Functional tests for the audience and tone API endpoints.
 */
class AudienceToneControllerTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests listing audience and tone options.
   */
  public function testListReturnsAudienceAndToneOptions(): void {
    $audience = $this->createEditorialTerm(
      'oe_ai_target_audience',
      'General public',
      'Plain-language content for non-experts.',
      'Use clear language.',
    );
    $tone = $this->createEditorialTerm(
      'oe_ai_tone',
      'Formal',
      'A neutral institutional tone.',
      'Use formal language.',
    );
    $this->loginApiUser();

    $audience_response = $this->httpGetJson('/api/ai/editorial-options/audience');
    $tone_response = $this->httpGetJson('/api/ai/editorial-options/tone');

    $this->assertSame(200, $audience_response['status']);
    $this->assertSame([
      'type' => 'audience',
      'options' => [
        [
          'id' => (string) $audience->id(),
          'label' => 'General public',
          'description' => 'Plain-language content for non-experts.',
        ],
      ],
    ], $audience_response['body']);

    $this->assertSame(200, $tone_response['status']);
    $this->assertSame([
      'type' => 'tone',
      'options' => [
        [
          'id' => (string) $tone->id(),
          'label' => 'Formal',
          'description' => 'A neutral institutional tone.',
        ],
      ],
    ], $tone_response['body']);
  }

  /**
   * Tests listing options when the vocabularies have no selectable terms.
   */
  public function testListWithEmptyVocabulariesReturnsEmptyArrays(): void {
    $this->loginApiUser();

    $audience_response = $this->httpGetJson('/api/ai/editorial-options/audience');
    $tone_response = $this->httpGetJson('/api/ai/editorial-options/tone');

    $this->assertSame(200, $audience_response['status']);
    $this->assertSame([
      'type' => 'audience',
      'options' => [],
    ], $audience_response['body']);

    $this->assertSame(200, $tone_response['status']);
    $this->assertSame([
      'type' => 'tone',
      'options' => [],
    ], $tone_response['body']);
  }

  /**
   * Tests saving audience and tone selections.
   */
  public function testApplyPersistsSelectedIds(): void {
    $audience = $this->createEditorialTerm('oe_ai_target_audience', 'Experts');
    $tone = $this->createEditorialTerm('oe_ai_tone', 'Technical');
    $user = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($user);
    $context = $this->buildSelectionContext((string) $session->id());

    $audience_response = $this->httpPostJson('/api/ai/editorial-selection/audience', [
      ...$context,
      'selectedId' => (string) $audience->id(),
    ]);
    $tone_response = $this->httpPostJson('/api/ai/editorial-selection/tone', [
      ...$context,
      'selectedId' => (string) $tone->id(),
    ]);

    $this->assertSame(200, $audience_response['status']);
    $this->assertSame((string) $audience->id(), $audience_response['body']['selection']['value']['id']);
    $this->assertSame(200, $tone_response['status']);
    $this->assertSame((string) $tone->id(), $tone_response['body']['selection']['value']['id']);
    $this->assertStoredSelection($context, 'audience', (string) $audience->id());
    $this->assertStoredSelection($context, 'tone', (string) $tone->id());
  }

  /**
   * Tests saving a new selection updates the stored value.
   */
  public function testReapplyingSelectionUpdatesStoredValues(): void {
    $first = $this->createEditorialTerm('oe_ai_target_audience', 'General public');
    $second = $this->createEditorialTerm('oe_ai_target_audience', 'Policy makers');
    $user = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($user);
    $context = $this->buildSelectionContext((string) $session->id());

    $this->httpPostJson('/api/ai/editorial-selection/audience', [
      ...$context,
      'selectedId' => (string) $first->id(),
    ]);
    $response = $this->httpPostJson('/api/ai/editorial-selection/audience', [
      ...$context,
      'selectedId' => (string) $second->id(),
    ]);

    $this->assertSame(200, $response['status']);
    $this->assertSame((string) $second->id(), $response['body']['selection']['value']['id']);
    $this->assertStoredSelection($context, 'audience', (string) $second->id());
  }

  /**
   * Tests invalid selections return validation errors.
   */
  public function testInvalidIdsReturnValidationErrors(): void {
    $user = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($user);

    $response = $this->httpPostJson('/api/ai/editorial-selection/audience', array_merge(
      $this->buildSelectionContext((string) $session->id()),
      [
        'selectedId' => '999999',
      ],
    ));

    $this->assertSame(400, $response['status']);
    $this->assertSame('invalid_selection', $response['body']['code']);
    $this->assertSame('Audience ID "999999" is not selectable.', $response['body']['message']);
  }

  /**
   * Tests terms from the wrong vocabulary return validation errors.
   */
  public function testCrossVocabularyIdsReturnValidationErrors(): void {
    $tone = $this->createEditorialTerm('oe_ai_tone', 'Formal');
    $user = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($user);

    $response = $this->httpPostJson('/api/ai/editorial-selection/audience', array_merge(
      $this->buildSelectionContext((string) $session->id()),
      [
        'selectedId' => (string) $tone->id(),
      ],
    ));

    $this->assertSame(400, $response['status']);
    $this->assertSame('invalid_selection', $response['body']['code']);
    $this->assertSame(sprintf(
      'Audience ID "%s" is not selectable.',
      $tone->id(),
    ), $response['body']['message']);
  }

  /**
   * Tests missing selection IDs return validation errors.
   */
  public function testMissingIdsReturnValidationErrors(): void {
    $user = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($user);

    $response = $this->httpPostJson('/api/ai/editorial-selection/audience', $this->buildSelectionContext((string) $session->id()));

    $this->assertSame(400, $response['status']);
    $this->assertSame('invalid_request', $response['body']['code']);
    $this->assertSame('selectedId is required and must be a non-empty string.', $response['body']['message']);
  }

  /**
   * Tests users without generation or session access are denied.
   */
  public function testUsersWithoutSessionOrGenerationAccessAreDenied(): void {
    $audience = $this->createEditorialTerm('oe_ai_target_audience', 'General public');
    $owner = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($owner);
    $context = $this->buildSelectionContext((string) $session->id());

    $no_generation_access = $this->loginApiUser();
    $response = $this->httpPostJson('/api/ai/editorial-selection/audience', [
      ...$context,
      'selectedId' => (string) $audience->id(),
    ]);
    $this->assertSame(403, $response['status']);
    $this->assertSame('forbidden', $response['body']['code']);
    $this->assertSame('You do not have permission to create oe_news content.', $response['body']['message']);

    $no_session_access = $this->loginApiUser(['create oe_news content']);
    $this->assertNotSame($owner->id(), $no_generation_access->id());
    $this->assertNotSame($owner->id(), $no_session_access->id());

    $response = $this->httpPostJson('/api/ai/editorial-selection/audience', [
      ...$context,
      'selectedId' => (string) $audience->id(),
    ]);
    $this->assertSame(403, $response['status']);
    $this->assertSame('forbidden', $response['body']['code']);
    $this->assertSame('You do not have permission to update this AI editorial session.', $response['body']['message']);
  }

  /**
   * Tests taxonomy administration permissions are not required.
   */
  public function testUsersDoNotNeedTaxonomyAdministrationPermissions(): void {
    $audience = $this->createEditorialTerm('oe_ai_target_audience', 'General public');
    $user = $this->loginApiUser(['create oe_news content']);
    $session = $this->createSession($user);

    $this->assertFalse($user->hasPermission('administer taxonomy'));

    $list_response = $this->httpGetJson('/api/ai/editorial-options/audience');
    $apply_response = $this->httpPostJson('/api/ai/editorial-selection/audience', array_merge(
      $this->buildSelectionContext((string) $session->id()),
      [
        'selectedId' => (string) $audience->id(),
      ],
    ));

    $this->assertSame(200, $list_response['status']);
    $this->assertSame(200, $apply_response['status']);
  }

  /**
   * Creates and logs in an API user.
   *
   * @param string[] $permissions
   *   Additional permissions to grant.
   */
  protected function loginApiUser(array $permissions = []): UserInterface {
    $user = $this->drupalCreateUser([
      'use oe ai assistant',
      ...$permissions,
    ]);
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    $this->drupalLogin($user);
    $this->container->get('current_user')->setAccount($user);

    return $user;
  }

  /**
   * Creates an editorial taxonomy term.
   */
  protected function createEditorialTerm(
    string $vid,
    string $name,
    string $description = 'Editorial option description.',
    string $prompt = 'Editorial prompt.',
  ): TermInterface {
    $term = Term::create([
      'vid' => $vid,
      'name' => $name,
      'description' => [
        'value' => $description,
        'format' => 'plain_text',
      ],
      'field_oe_ai_prompt' => $prompt,
    ]);
    $term->save();

    return $term;
  }

  /**
   * Builds the standard selection context.
   *
   * @return array{entityTypeId: string, bundle: string, sessionId: string}
   *   The selection context.
   */
  protected function buildSelectionContext(string $sessionId): array {
    return [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $sessionId,
    ];
  }

  /**
   * Sends a GET request and returns the decoded JSON response.
   *
   * @param string $url
   *   The URL to request.
   *
   * @return array{status: int, body: array<string, mixed>}
   *   The response status and decoded body.
   */
  protected function httpGetJson(string $url): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();
    $client->request('GET', $this->baseUrl . $url);
    $response = $client->getResponse();

    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode($response->getContent(), TRUE),
    ];
  }

  /**
   * Sends a POST request and returns the decoded JSON response.
   *
   * @param string $url
   *   The URL to post to.
   * @param array<string, mixed> $body
   *   The request body.
   *
   * @return array{status: int, body: array<string, mixed>}
   *   The response status and decoded body.
   */
  protected function httpPostJson(string $url, array $body): array {
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
   * Asserts a selection was stored for the current user.
   *
   * @param array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string} $context
   *   The selection context.
   * @param string $optionType
   *   The option type.
   * @param string $expectedId
   *   The expected selected term ID.
   */
  protected function assertStoredSelection(array $context, string $optionType, string $expectedId): void {
    $this->assertSame(
      $expectedId,
      $this->container->get(DraftingSelectionStoreInterface::class)->load($context, $optionType),
    );
  }

}
