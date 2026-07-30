<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the drafting-template selection handler filtering.
 */
#[Group('oe_ai_assistant')]
class AiDraftingTemplateSelectionTest extends AiEditorialSessionKernelTestBase {

  /**
   * Creates enabled/disabled templates across two bundles.
   */
  private function seedTemplates(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template');
    $storage->create([
      'id' => 'news_a',
      'label' => 'News A',
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
    $storage->create([
      'id' => 'news_disabled',
      'label' => 'News disabled',
      'status' => FALSE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
    $storage->create([
      'id' => 'contact_a',
      'label' => 'Contact A',
      'content_type' => 'oe_contact',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
  }

  /**
   * Returns the handler instance for a given host configuration.
   */
  private function handler(array $configuration) {
    return $this->container->get('plugin.manager.entity_reference_selection')
      ->getInstance(['target_type' => 'ai_drafting_template', 'handler' => 'ai_drafting_template_selection'] + $configuration);
  }

  /**
   * Only enabled templates for the session's bundle are referenceable.
   */
  public function testReturnsOnlyEnabledTemplatesForSessionBundle(): void {
    $this->seedTemplates();
    $session = $this->createSession($this->createUser());

    $referenceable = $this->handler(['entity' => $session])->getReferenceableEntities();
    $ids = array_keys($referenceable['ai_drafting_template'] ?? []);
    sort($ids);

    $this->assertSame(['news_a'], $ids);
  }

  /**
   * Without a host session no templates are referenceable.
   */
  public function testReturnsNothingWithoutHostSession(): void {
    $this->seedTemplates();

    $this->assertSame([], $this->handler([])->getReferenceableEntities());
  }

}
