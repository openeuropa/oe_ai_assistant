<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Utility;

use Drupal\oe_ai_assistant\Utility\MessageContent;
use Drupal\Tests\UnitTestCase;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading the text of stored Neuron message content.
 */
#[Group('oe_ai_assistant')]
class MessageContentTest extends UnitTestCase {

  /**
   * Tests that only the text blocks make up the text.
   */
  #[DataProvider('contentProvider')]
  public function testToText(array $content, string $expected): void {
    $this->assertSame($expected, MessageContent::toText($content));
  }

  /**
   * Stored message content and the text it holds.
   */
  public static function contentProvider(): array {
    $image = new ImageContent('https://example.com/a.png', SourceType::URL);

    return [
      'one text block' => [self::stored(new TextContent('Hello')), 'Hello'],
      // A reply can arrive in several text blocks. A separator would split
      // the words at the block boundaries.
      'text blocks join with no separator' => [
        self::stored(new TextContent('Hel'), new TextContent('lo')),
        'Hello',
      ],
      // Reasoning extends text in Neuron, but the model's thinking is not
      // part of the reply.
      'reasoning is left out' => [
        self::stored(new ReasoningContent('Think first.'), new TextContent('Answer')),
        'Answer',
      ],
      // An image block also has content, which is its URL.
      'an image is left out' => [
        self::stored($image, new TextContent('Caption')),
        'Caption',
      ],
      'no text block' => [self::stored($image), ''],
      'no block' => [[], ''],
      // The blocks come from a text column, so their shape is not certain.
      'an entry that is not a block' => [
        ['text', NULL, ...self::stored(new TextContent('Hi'))],
        'Hi',
      ],
      'a text block without content' => [[['type' => 'text']], ''],
    ];
  }

  /**
   * The blocks as the message store keeps them: decoded from JSON.
   *
   * @param \NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface ...$blocks
   *   The blocks of one message.
   *
   * @return array
   *   The decoded blocks.
   */
  private static function stored(ContentBlockInterface ...$blocks): array {
    return json_decode(json_encode($blocks, JSON_THROW_ON_ERROR), TRUE, flags: JSON_THROW_ON_ERROR);
  }

}
