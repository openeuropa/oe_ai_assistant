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

    $this->assertNotNull(Vocabulary::load('oe_ai_tone'));

    $fieldStorage = FieldStorageConfig::loadByName('taxonomy_term', 'field_oe_ai_prompt');
    $this->assertNotNull($fieldStorage);
    $this->assertSame('string_long', $fieldStorage->getType());

    $toneField = FieldConfig::loadByName('taxonomy_term', 'oe_ai_tone', 'field_oe_ai_prompt');

    $this->assertNotNull($toneField);
    $this->assertSame('string_long', $toneField->getType());

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
        'description' => 'A detailed and structured tone using specialized terminology for expert contexts.',
        'prompt' => 'Use domain-specific terminology precisely. Include technical detail and data. Structure content with clear headings and logical flow.',
      ],
    ];

    $this->assertSame($expectedTones, $this->loadTermsByVocabulary('oe_ai_tone'));
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
      $values[$term->label()]['prompt'] = $term->get('field_oe_ai_prompt')->value;
    }
    ksort($values);
    return $values;
  }

}
