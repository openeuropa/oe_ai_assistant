<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\taxonomy\Entity\Term;

/**
 * Integration tests for the DraftingPlugin set-tone action.
 */
class DraftingPluginSetToneTest extends DraftingPluginTestBase {

  /**
   * Tests that a valid tone selection is saved on the session.
   */
  public function testSetToneAcceptsValidTone(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $toneId = $this->getTermIdByName('oe_ai_tone', 'Formal');

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $toneId,
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['status' => 'ok'], json_decode($result['body'], TRUE));

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
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => '1',
    ]);

    $this->assertSame(400, $result['status']);
    $body = json_decode($result['body'], TRUE);
    $this->assertSame('bad_request', $body['code']);
    $this->assertStringContainsString('toneId', $body['message']);
  }

  /**
   * Tests that unknown term IDs are rejected.
   */
  public function testSetToneRejectsInvalidTermId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => '1',
      'toneId' => '999999',
    ]);

    $this->assertSame(400, $result['status']);
    $body = json_decode($result['body'], TRUE);
    $this->assertSame('invalid_context', $body['code']);
    $this->assertStringContainsString('oe_ai_tone', $body['message']);
  }

  /**
   * Tests that tone IDs from the wrong vocabulary are rejected.
   */
  public function testSetToneRejectsWrongVocabularyId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $otherTerm = Term::create([
      'vid' => 'news_tags',
      'name' => 'Wrong vocabulary',
    ]);
    $otherTerm->save();
    $this->markEntityForCleanup($otherTerm);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => '1',
      'toneId' => (string) $otherTerm->id(),
    ]);

    $this->assertSame(400, $result['status']);
    $body = json_decode($result['body'], TRUE);
    $this->assertSame('invalid_context', $body['code']);
    $this->assertStringContainsString('oe_ai_tone', $body['message']);
  }

}
