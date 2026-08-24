<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;

/**
 * Tests the AI drafting template form validation.
 */
final class AiDraftingTemplateFormTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests that a valid template can be edited and saved.
   */
  public function testEditingValidTemplate(): void {
    $admin = $this->drupalCreateUser(['administer ai_drafting_template']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/ai-editorial/templates/news_default');
    $this->submitForm(['description' => 'Tweaked description.'], 'Save');

    $this->assertSession()->statusMessageContains('Updated AI drafting template', 'status');
    $this->assertSame('Tweaked description.', AiDraftingTemplate::load('news_default')->getDescription());
  }

  /**
   * Tests that a structural violation still blocks the form.
   */
  public function testStructuralViolationStillBlocksForm(): void {
    $admin = $this->drupalCreateUser(['administer ai_drafting_template']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/ai-editorial/templates/news_default');
    $this->submitForm(['fields_yaml' => "field_does_not_exist:\n  prompt: 'Bad.'"], 'Save');

    $assert = $this->assertSession();
    $assert->statusMessageNotContains('Updated AI drafting template');
    $assert->statusMessageContains('field_does_not_exist', 'error');
  }

}
