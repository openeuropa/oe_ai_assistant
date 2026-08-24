<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the EditorialContext value object and its provenance snapshot.
 *
 * The populated-documents case uses fixture descriptors of both categories
 * (context and publishable) to ensure snapshot wiring is proven before the
 * documents backend is fully implemented.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext
 */
class EditorialContextTest extends UnitTestCase {

  /**
   * Tests the snapshot of a fully populated context.
   */
  public function testToSnapshotWithFullContext(): void {
    $documents = [
      [
        'id' => '12',
        'title' => 'Climate briefing note',
        'category' => 'context',
        'summary' => 'Key figures on EU emissions.',
        'meta' => ['mime' => 'application/pdf'],
      ],
      [
        'id' => '15',
        'title' => 'Hero image',
        'category' => 'publishable',
        'summary' => 'Wind turbines at sunset.',
        'meta' => ['mime' => 'image/png'],
      ],
    ];
    $context = new EditorialContext(
      toneId: '3',
      toneLabel: 'Formal',
      tonePrompt: 'Use professional, institutional language.',
      templateId: 'news_default',
      templateLabel: 'News default',
      documents: $documents,
    );

    $snapshot = $context->toSnapshot();

    $this->assertSame(['id' => '3', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'], $snapshot['tone']);
    $this->assertSame(
      ['id' => 'news_default', 'label' => 'News default'],
      $snapshot['template'],
    );
    $this->assertSame($documents, $snapshot['documents']);
  }

  /**
   * Tests that missing tone and template snapshot as NULL, not as arrays.
   */
  public function testToSnapshotWithEmptyContext(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL);

    $snapshot = $context->toSnapshot();

    $this->assertNull($snapshot['tone']);
    $this->assertNull($snapshot['template']);
    $this->assertSame([], $snapshot['documents']);
  }

  /**
   * Tests that the prompt block is built from tone label and guidelines.
   */
  public function testToPromptWithFullContext(): void {
    $context = new EditorialContext(
      toneId: '3',
      toneLabel: 'Formal',
      tonePrompt: 'Use professional, institutional language.',
      templateId: 'news_default',
      templateLabel: 'News default',
    );

    $prompt = $context->toPrompt();

    $this->assertStringContainsString(
      'Editorial context selected by the editor for this draft:',
      $prompt,
    );
    $this->assertStringContainsString('- Tone: Formal', $prompt);
    $this->assertStringContainsString(
      '- Tone guidelines: Use professional, institutional language.',
      $prompt,
    );
    $this->assertStringContainsString(
      'Follow the tone guidelines',
      $prompt,
    );
  }

  /**
   * Tests that toPrompt returns an empty string when no tone is set.
   */
  public function testToPromptWithEmptyContext(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL);
    $this->assertSame('', $context->toPrompt());
  }

}
