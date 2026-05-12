<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Verifies the editorial taxonomy install state.
 */
class EditorialTaxonomyInstallTest extends KernelTestBase {

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
   * Tests that the vocabularies, field config, and default terms are installed.
   */
  public function testEditorialTaxonomyInstallState(): void {
    \Drupal::service('module_installer')->install(['oe_ai_assistant_test']);

    $this->assertTrue(\Drupal::service('module_handler')->moduleExists('oe_ai_assistant'));
    $this->assertTrue(\Drupal::service('module_handler')->moduleExists('oe_ai_assistant_test'));

    $this->assertNotNull(Vocabulary::load('ai_target_audience'));
    $this->assertNotNull(Vocabulary::load('ai_tone'));

    $fieldStorage = FieldStorageConfig::loadByName('taxonomy_term', 'field_ai_prompt');
    $this->assertNotNull($fieldStorage);
    $this->assertSame('string_long', $fieldStorage->getType());

    $audienceField = FieldConfig::loadByName('taxonomy_term', 'ai_target_audience', 'field_ai_prompt');
    $toneField = FieldConfig::loadByName('taxonomy_term', 'ai_tone', 'field_ai_prompt');

    $this->assertNotNull($audienceField);
    $this->assertNotNull($toneField);
    $this->assertSame('string_long', $audienceField->getType());
    $this->assertSame('string_long', $toneField->getType());

    $expectedAudiences = [
      'Business and industry' => [
        'description' => 'Content focused on professional stakeholders, emphasizing practical impact, compliance, and business relevance.',
        'prompt' => 'Use professional language. Emphasize practical implications, compliance requirements, and economic impact. Be specific about timelines and actions.',
      ],
      'General public' => [
        'description' => 'Content should be easy to understand for non-experts, using plain language and minimal jargon.',
        'prompt' => 'Write in clear, accessible language. Avoid jargon and acronyms. Use short sentences. Assume no prior knowledge of EU policy.',
      ],
      'Policy makers' => [
        'description' => 'Content tailored for experts, using precise terminology and references to policy and legislation.',
        'prompt' => 'Use precise language. Reference regulatory frameworks and legislative instruments where relevant. Assume domain expertise.',
      ],
      'Press and media' => [
        'description' => 'Content optimized for news coverage, highlighting key facts, figures, and timely angles.',
        'prompt' => 'Lead with the newsworthy angle. Use a factual, quotable style. Include key figures and dates. Keep paragraphs short.',
      ],
      'Young audience' => [
        'description' => 'Content aimed at younger readers, with a simple, engaging tone and relatable examples.',
        'prompt' => 'Use an approachable, engaging tone. Explain concepts simply. Avoid bureaucratic language. Use concrete examples.',
      ],
    ];
    $expectedTones = [
      'Conversational' => [
        'description' => 'A friendly and informal tone that speaks directly to the reader.',
        'prompt' => 'Write in a friendly, approachable style. Use contractions naturally. Address the reader directly. Keep sentences varied in length.',
      ],
      'Formal' => [
        'description' => 'A professional and neutral tone suitable for official or institutional communication.',
        'prompt' => 'Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
      ],
      'Inspirational' => [
        'description' => 'A motivating and forward-looking tone that emphasizes positive outcomes and shared goals.',
        'prompt' => 'Use forward-looking, motivational language. Emphasize positive outcomes and shared goals. Appeal to values and aspirations.',
      ],
      'Technical' => [
        'description' => 'A detailed and structured tone using specialized terminology for expert audiences.',
        'prompt' => 'Use domain-specific terminology precisely. Include technical detail and data. Structure content with clear headings and logical flow.',
      ],
    ];

    $this->assertSame($expectedAudiences, $this->loadTermsByVocabulary('ai_target_audience'));
    $this->assertSame($expectedTones, $this->loadTermsByVocabulary('ai_tone'));
  }

  /**
   * Loads term names and prompts keyed by term name for a vocabulary.
   *
   * @param string $vid
   *   Vocabulary machine name.
   *
   * @return array<string, string>
   *   The term names keyed to their prompt text.
   */
  protected function loadTermsByVocabulary(string $vid): array {
    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => $vid]);
    $values = [];
    foreach ($terms as $term) {
      $values[$term->label()]['description'] = $term->get('description')->value;
      $values[$term->label()]['prompt'] = $term->get('field_ai_prompt')->value;
    }
    ksort($values);
    return $values;
  }

}
