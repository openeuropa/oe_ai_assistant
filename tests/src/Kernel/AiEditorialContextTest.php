<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\AiEditorialContext;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;

/**
 * Tests the editorial context service.
 */
class AiEditorialContextTest extends KernelTestBase {

  /**
   * The editorial context service under test.
   *
   * @var \Drupal\oe_ai_assistant\Service\AiEditorialContextInterface
   */
  protected AiEditorialContextInterface $editorialContext;

  /**
   * Modules required to bootstrap the kernel test environment.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('module_installer')->install(['oe_ai_assistant_test']);
    $this->editorialContext = \Drupal::service(AiEditorialContextInterface::class);
  }

  /**
   * Tests that the interface is wired to the concrete service.
   */
  public function testServiceRegistration(): void {
    $this->assertInstanceOf(AiEditorialContextInterface::class, $this->editorialContext);
    $this->assertInstanceOf(AiEditorialContext::class, $this->editorialContext);
  }

  /**
   * Tests loading available tones.
   */
  public function testAvailableOptions(): void {
    $tones = $this->editorialContext->getAvailableTones();
    $this->assertSame([
      [
        'label' => 'Conversational',
        'description' => 'A friendly and informal tone that speaks directly to the reader.',
        'oe_ai_prompt' => 'Write in a friendly, approachable style. Use contractions naturally. Address the reader directly. Keep sentences varied in length.',
      ],
      [
        'label' => 'Formal',
        'description' => 'A professional and neutral tone suitable for official or institutional communication.',
        'oe_ai_prompt' => 'Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
      ],
      [
        'label' => 'Inspirational',
        'description' => 'A motivating and forward-looking tone that emphasizes positive outcomes and shared goals.',
        'oe_ai_prompt' => 'Use forward-looking, motivational language. Emphasize positive outcomes and shared goals. Appeal to values and aspirations.',
      ],
      [
        'label' => 'Technical',
        'description' => 'A detailed and structured tone using specialized terminology for expert contexts.',
        'oe_ai_prompt' => 'Use domain-specific terminology precisely. Include technical detail and data. Structure content with clear headings and logical flow.',
      ],
    ], $this->stripIds($tones));
  }

  /**
   * Tests building the selection prompt.
   */
  public function testBuildSelectionPrompt(): void {
    $this->assertSame(implode("\n", [
      'Before drafting, ask the user to select a writing tone.',
      '',
      'Available tones:',
      '- Conversational: Write in a friendly, approachable style. Use contractions naturally. Address the reader directly. Keep sentences varied in length.',
      '- Formal: Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
      '- Inspirational: Use forward-looking, motivational language. Emphasize positive outcomes and shared goals. Appeal to values and aspirations.',
      '- Technical: Use domain-specific terminology precisely. Include technical detail and data. Structure content with clear headings and logical flow.',
      '',
      "Present these options and wait for the user's selection before proceeding.",
    ]), $this->editorialContext->buildSelectionPrompt());
  }

  /**
   * Tests building the prompt for a selected tone.
   */
  public function testBuildSelectedPrompt(): void {
    $toneId = $this->getOptionIdByLabel(
      $this->editorialContext->getAvailableTones(),
      'Formal',
    );

    $this->assertSame(implode("\n", [
      'The user has selected:',
      '- Tone: Formal',
      '',
      'Apply these guidelines when drafting:',
      '- Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
    ]), $this->editorialContext->buildSelectedPrompt($toneId));
  }

  /**
   * Tests that invalid term selections are rejected.
   */
  public function testBuildSelectedPromptRejectsInvalidTerms(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->editorialContext->buildSelectedPrompt('999');
  }

  /**
   * Removes IDs from the service output for stable assertions.
   *
   * @param array<int, array{id: string, label: string, description: string, oe_ai_prompt: string}> $options
   *   The service output.
   *
   * @return array<int, array{label: string, description: string, oe_ai_prompt: string}>
   *   The options without IDs.
   */
  protected function stripIds(array $options): array {
    return array_map(
      static fn (array $option): array => [
        'label' => $option['label'],
        'description' => $option['description'],
        'oe_ai_prompt' => $option['oe_ai_prompt'],
      ],
      $options,
    );
  }

  /**
   * Returns the generated ID for an option label.
   *
   * @param array<int, array{id: string, label: string, description: string, oe_ai_prompt: string}> $options
   *   The service output.
   * @param string $label
   *   The option label to find.
   *
   * @return string
   *   The matching option ID.
   */
  protected function getOptionIdByLabel(array $options, string $label): string {
    foreach ($options as $option) {
      if ($option['label'] === $label) {
        return $option['id'];
      }
    }

    $this->fail(sprintf('Option "%s" was not found.', $label));
    throw new \LogicException('Unreachable.');
  }

}
