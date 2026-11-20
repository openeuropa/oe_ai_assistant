<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration test for the session activity tool.
 *
 * The editor picks two tones over HTTP, then asks the assistant about them.
 * The mock provider scripts both replies, so what the test pins is who
 * reads the record. The answering agent gets it in its prompt, and the
 * result the editorial thread keeps holds only the answer.
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
          'function' => [
            'name' => 'context_get_session_activity',
            'arguments' => '{"question": "Which tone did I pick first?"}',
          ],
        ],
      ],
    ));
    // The mock answers in call order. GetSessionActivityNeuronAgent runs
    // inside the tool call and answers first, then the editorial agent
    // reports that answer to the editor.
    MockAiProvider::enqueue(new MockResponse(text: 'Formal came first.'));
    MockAiProvider::enqueue(new MockResponse(text: 'You picked Formal first.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Which tone did I pick first?',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));
    $this->assertStringContainsString('You picked Formal first.', $this->streamedText($result['body']));

    // The tool result the editorial thread keeps holds the answer and
    // none of the record.
    $results = $session->conversationToolResults();
    $this->assertSame('context_get_session_activity', $results[0]['name']);
    $this->assertSame(['answer' => 'Formal came first.'], $results[0]['result']);

    // The answering agent gets the whole record in its prompt. The editor
    // who asked reads as "you" there, not under their display name.
    $prompt = $this->activityPrompt();
    $this->assertStringContainsString('| you | Tone set to "Formal".', $prompt);
    $this->assertStringContainsString('| you | Tone changed from "Formal" to "Technical".', $prompt);
    // The record runs oldest first, so the tone picked first comes first.
    $this->assertLessThan(
      strpos($prompt, 'Tone changed from'),
      strpos($prompt, 'Tone set to'),
    );
  }

  /**
   * Reads the prompt the session_activity agent ran with.
   */
  private function activityPrompt(): string {
    foreach (MockAiProvider::getCallLog() as $call) {
      $prompt = (string) ($call['system_prompt'] ?? '');
      if (str_contains($prompt, 'You answer one question about what happened')) {
        return $prompt;
      }
    }

    $this->fail('The session_activity agent was never called.');
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
