<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration test for the session activity tool.
 *
 * The editor picks two tones over HTTP, then asks the assistant about
 * them. The mock provider scripts the reply, so what this pins is the
 * tool result the turn recorded: the sentences the two set-tone calls
 * wrote, in order, with their author.
 */
class DraftingPluginSessionActivityTest extends DraftingPluginTestBase {

  /**
   * The agent answers what happened in the session from the activity tool.
   */
  public function testAgentReadsTheActivityOfTheSession(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->setTone($session->id(), $this->getTermIdByName('oe_ai_tone', 'Formal'));
    $this->setTone($session->id(), $this->getTermIdByName('oe_ai_tone', 'Technical'));

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'context_get_session_activity', 'arguments' => '{}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'You picked Formal first.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Which tone did I pick first?',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));
    $this->assertStringContainsString('You picked Formal first.', $this->streamedText($result['body']));

    $results = $session->conversationToolResults();
    $this->assertSame('context_get_session_activity', $results[0]['name']);
    $activity = $results[0]['result'];
    $this->assertSame(0, $activity['earlier_entries_omitted']);
    $this->assertSame([
      'Tone set to "Formal".',
      'Tone changed from "Formal" to "Technical".',
    ], array_column($activity['activity'], 'message'));
    $this->assertSame(
      [$user->getDisplayName(), $user->getDisplayName()],
      array_column($activity['activity'], 'by'),
    );
  }

  /**
   * Runs the set-tone action over HTTP.
   */
  private function setTone(string $sessionId, string $toneId): void {
    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $sessionId,
      'toneId' => $toneId,
    ]);

    $this->assertSame(200, $result['status'],
      'set-tone should return 200. Body: ' . substr($result['body'], 0, 500));
  }

  /**
   * Reassembles the text the stream delivered as deltas.
   */
  private function streamedText(string $body): string {
    $text = '';
    foreach (explode("\n", $body) as $line) {
      if (!str_starts_with($line, 'data: ')) {
        continue;
      }
      $event = json_decode(substr($line, strlen('data: ')), TRUE);
      if (is_array($event) && ($event['type'] ?? '') === 'text-delta') {
        $text .= (string) ($event['delta'] ?? '');
      }
    }

    return $text;
  }

}
