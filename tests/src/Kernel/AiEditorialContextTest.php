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
   * Tests loading available audiences and tones.
   */
  public function testAvailableOptions(): void {
    $audiences = $this->editorialContext->getAvailableAudiences();
    $tones = $this->editorialContext->getAvailableTones();
    $this->assertSame([
      [
        'name' => 'Business and industry',
        'oe_ai_prompt' => 'Use professional language. Emphasize practical implications, compliance requirements, and economic impact. Be specific about timelines and actions.',
      ],
      [
        'name' => 'General public',
        'oe_ai_prompt' => 'Write in clear, accessible language. Avoid jargon and acronyms. Use short sentences. Assume no prior knowledge of EU policy.',
      ],
      [
        'name' => 'Policy makers',
        'oe_ai_prompt' => 'Use precise language. Reference regulatory frameworks and legislative instruments where relevant. Assume domain expertise.',
      ],
      [
        'name' => 'Press and media',
        'oe_ai_prompt' => 'Lead with the newsworthy angle. Use a factual, quotable style. Include key figures and dates. Keep paragraphs short.',
      ],
      [
        'name' => 'Young audience',
        'oe_ai_prompt' => 'Use an approachable, engaging tone. Explain concepts simply. Avoid bureaucratic language. Use concrete examples.',
      ],
    ], $this->stripIds($audiences));

    $this->assertSame([
      [
        'name' => 'Conversational',
        'oe_ai_prompt' => 'Write in a friendly, approachable style. Use contractions naturally. Address the reader directly. Keep sentences varied in length.',
      ],
      [
        'name' => 'Formal',
        'oe_ai_prompt' => 'Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
      ],
      [
        'name' => 'Inspirational',
        'oe_ai_prompt' => 'Use forward-looking, motivational language. Emphasize positive outcomes and shared goals. Appeal to values and aspirations.',
      ],
      [
        'name' => 'Technical',
        'oe_ai_prompt' => 'Use domain-specific terminology precisely. Include technical detail and data. Structure content with clear headings and logical flow.',
      ],
    ], $this->stripIds($tones));
  }

  /**
   * Tests building the selection prompt.
   */
  public function testBuildSelectionPrompt(): void {
    $this->assertSame(implode("\n", [
      'Before drafting, ask the user to select a target audience and writing tone.',
      '',
      'Available target audiences:',
      '- Business and industry: Use professional language. Emphasize practical implications, compliance requirements, and economic impact. Be specific about timelines and actions.',
      '- General public: Write in clear, accessible language. Avoid jargon and acronyms. Use short sentences. Assume no prior knowledge of EU policy.',
      '- Policy makers: Use precise language. Reference regulatory frameworks and legislative instruments where relevant. Assume domain expertise.',
      '- Press and media: Lead with the newsworthy angle. Use a factual, quotable style. Include key figures and dates. Keep paragraphs short.',
      '- Young audience: Use an approachable, engaging tone. Explain concepts simply. Avoid bureaucratic language. Use concrete examples.',
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
   * Tests building the prompt for a selected audience and tone.
   */
  public function testBuildSelectedPrompt(): void {
    $audienceId = $this->getOptionIdByName(
      $this->editorialContext->getAvailableAudiences(),
      'Policy makers',
    );
    $toneId = $this->getOptionIdByName(
      $this->editorialContext->getAvailableTones(),
      'Formal',
    );

    $this->assertSame(implode("\n", [
      'The user has selected:',
      '- Target audience: Policy makers',
      '- Tone: Formal',
      '',
      'Apply these guidelines when drafting:',
      '- Use precise language. Reference regulatory frameworks and legislative instruments where relevant. Assume domain expertise.',
      '- Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
    ]), $this->editorialContext->buildSelectedPrompt($audienceId, $toneId));
  }

  /**
   * Tests that invalid term selections are rejected.
   */
  public function testBuildSelectedPromptRejectsInvalidTerms(): void {
    $this->expectException(\InvalidArgumentException::class);
    $toneId = $this->getOptionIdByName(
      $this->editorialContext->getAvailableTones(),
      'Formal',
    );
    $this->editorialContext->buildSelectedPrompt('999', $toneId);
  }

  /**
   * Removes IDs from the service output for stable assertions.
   *
   * @param array<int, array{id: string, name: string, oe_ai_prompt: string}> $options
   *   The service output.
   *
   * @return array<int, array{name: string, oe_ai_prompt: string}>
   *   The options without IDs.
   */
  protected function stripIds(array $options): array {
    return array_map(
      static fn (array $option): array => [
        'name' => $option['name'],
        'oe_ai_prompt' => $option['oe_ai_prompt'],
      ],
      $options,
    );
  }

  /**
   * Returns the generated ID for an option label.
   *
   * @param array<int, array{id: string, name: string, oe_ai_prompt: string}> $options
   *   The service output.
   * @param string $name
   *   The option label to find.
   *
   * @return string
   *   The matching option ID.
   */
  protected function getOptionIdByName(array $options, string $name): string {
    foreach ($options as $option) {
      if ($option['name'] === $name) {
        return $option['id'];
      }
    }

    $this->fail(sprintf('Option "%s" was not found.', $name));
    throw new \LogicException('Unreachable.');
  }

}
