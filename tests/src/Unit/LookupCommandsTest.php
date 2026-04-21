<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\oe_ai_assistant\Commands\LookupCommands;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LookupCommands.
 */
class LookupCommandsTest extends TestCase {

  /**
   * Tests that the paragraphs lookup executes the paragraph plugin.
   */
  public function testLookupReturnsParagraphTypesStructuredOutput(): void {
    $structuredOutput = [
      'paragraph_types' => [
        [
          'bundle' => 'text_block',
          'label' => 'Text block',
        ],
      ],
    ];

    $manager = $this->createManager(
      'lookup_paragraph_types',
      [
        'entity_type' => 'node',
        'bundle' => 'oe_news',
        'field_name' => 'field_content_paragraphs',
      ],
      $structuredOutput,
    );

    $command = $this->createCommand($manager);
    $result = $command->lookup(
      'paragraphs',
      'node',
      'oe_news',
      'field_content_paragraphs',
    );

    $this->assertSame($structuredOutput, $result->getArrayCopy());
  }

  /**
   * Tests that the media lookup forwards query inputs to the plugin.
   */
  public function testLookupReturnsMediaStructuredOutput(): void {
    $structuredOutput = [
      'media' => [
        [
          'target_id' => 7,
          'label' => 'Hero image',
          'bundle' => 'image',
        ],
      ],
    ];

    $manager = $this->createManager(
      'lookup_media',
      [
        'entity_type' => 'node',
        'bundle' => 'oe_news',
        'field_name' => 'field_media',
        'query' => 'hero',
        'limit' => 5,
      ],
      $structuredOutput,
    );

    $command = $this->createCommand($manager);
    $result = $command->lookup(
      'media',
      'node',
      'oe_news',
      'field_media',
      [
        'query' => 'hero',
        'limit' => '5',
      ],
    );

    $this->assertSame($structuredOutput, $result->getArrayCopy());
  }

  /**
   * Tests that media lookups require a query option.
   */
  public function testLookupMediaRequiresQuery(): void {
    $manager = new class() {

      /**
       * Fails if the plugin manager is called unexpectedly.
       */
      public function getFunctionCallFromFunctionName(string $functionName): never {
        throw new \LogicException('Should not be called.');
      }

    };

    $command = $this->createCommand($manager, FALSE);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Missing required input(s): query.');

    $command->lookup(
      'media',
      'node',
      'oe_news',
      'field_media',
      [],
    );
  }

  /**
   * Tests that unsupported lookup types are rejected.
   */
  public function testLookupRejectsUnsupportedType(): void {
    $manager = new class() {

      /**
       * Fails if the plugin manager is called unexpectedly.
       */
      public function getFunctionCallFromFunctionName(string $functionName): never {
        throw new \LogicException('Should not be called.');
      }

    };

    $command = $this->createCommand($manager, FALSE);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
      'Unsupported lookup type "taxonomy". Allowed values: paragraphs, media.',
    );

    $command->lookup(
      'taxonomy',
      'node',
      'oe_news',
      'field_topics',
    );
  }

  /**
   * Tests that invalid limits are rejected.
   */
  public function testLookupRejectsInvalidLimit(): void {
    $manager = new class() {

      /**
       * Fails if the plugin manager is called unexpectedly.
       */
      public function getFunctionCallFromFunctionName(string $functionName): never {
        throw new \LogicException('Should not be called.');
      }

    };

    $command = $this->createCommand($manager, FALSE);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The "limit" option must be an integer.');

    $command->lookup(
      'media',
      'node',
      'oe_news',
      'field_media',
      [
        'query' => 'hero',
        'limit' => 'five',
      ],
    );
  }

  /**
   * Tests that invalid user IDs are rejected.
   */
  public function testLookupRejectsInvalidUid(): void {
    $manager = new class() {

      /**
       * Fails if the plugin manager is called unexpectedly.
       */
      public function getFunctionCallFromFunctionName(string $functionName): never {
        throw new \LogicException('Should not be called.');
      }

    };

    $command = $this->createCommand($manager, FALSE);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The "uid" option must be a positive integer.');

    $command->lookup(
      'paragraphs',
      'node',
      'oe_news',
      'field_content_paragraphs',
      [
        'uid' => 'abc',
      ],
    );
  }

  /**
   * Tests that a non-executable lookup plugin is rejected.
   */
  public function testLookupRejectsNonExecutablePlugin(): void {
    $plugin = new class() {

      /**
       * Accepts context values for the non-executable plugin double.
       */
      public function setContextValue(string $name, mixed $value): void {}

    };

    $manager = new class($plugin) {

      public function __construct(
        private readonly object $plugin,
      ) {}

      /**
       * Returns the configured plugin double.
       */
      public function getFunctionCallFromFunctionName(string $functionName): object {
        TestCase::assertSame('lookup_paragraph_types', $functionName);
        return $this->plugin;
      }

    };

    $command = $this->createCommand($manager);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The lookup_paragraph_types plugin is not executable.');

    $command->lookup(
      'paragraphs',
      'node',
      'oe_news',
      'field_content_paragraphs',
    );
  }

  /**
   * Creates a command instance with mocked runtime services.
   */
  private function createCommand(
    object $manager,
    bool $expectAccountSwitch = TRUE,
  ): LookupCommands {
    $account = $this->createMock(AccountInterface::class);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($expectAccountSwitch ? $this->once() : $this->never())
      ->method('load')
      ->with(1)
      ->willReturn($account);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($expectAccountSwitch ? $this->once() : $this->never())
      ->method('getStorage')
      ->with('user')
      ->willReturn($storage);

    $accountSwitcher = $this->createMock(AccountSwitcherInterface::class);
    $accountSwitcher->expects($expectAccountSwitch ? $this->once() : $this->never())
      ->method('switchTo')
      ->with($account);
    $accountSwitcher->expects($expectAccountSwitch ? $this->once() : $this->never())
      ->method('switchBack');

    return new LookupCommands($manager, $entityTypeManager, $accountSwitcher);
  }

  /**
   * Creates a function-call manager mock for one lookup execution.
   *
   * @param string $functionName
   *   The expected function-call plugin name.
   * @param array<string, mixed> $expectedContext
   *   The expected context values.
   * @param array<string, mixed> $structuredOutput
   *   The structured output returned by the plugin.
   *
   * @return object
   *   The configured manager double.
   */
  private function createManager(
    string $functionName,
    array $expectedContext,
    array $structuredOutput,
  ): object {
    $capturedContext = [];

    $plugin = $this->createMock(StructuredExecutableFunctionCallInterface::class);
    $plugin->expects($this->exactly(count($expectedContext)))
      ->method('setContextValue')
      ->willReturnCallback(function (string $name, mixed $value) use (&$capturedContext): void {
        $capturedContext[$name] = $value;
      });
    $plugin->expects($this->once())
      ->method('execute')
      ->willReturnCallback(function () use (&$capturedContext, $expectedContext): void {
        TestCase::assertSame($expectedContext, $capturedContext);
      });
    $plugin->expects($this->once())
      ->method('getStructuredOutput')
      ->willReturn($structuredOutput);

    return new class($plugin, $functionName) {

      public function __construct(
        private readonly StructuredExecutableFunctionCallInterface $plugin,
        private readonly string $functionName,
      ) {}

      /**
       * Returns the configured executable plugin double.
       */
      public function getFunctionCallFromFunctionName(string $functionName): StructuredExecutableFunctionCallInterface {
        TestCase::assertSame($this->functionName, $functionName);
        return $this->plugin;
      }

    };
  }

}
