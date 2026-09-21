<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the draftable-node-type selection handler filtering.
 *
 * The oe_ai_assistant_test module installs oe_news and oe_contact node
 * types plus templates for both: news_default (and friends) for oe_news,
 * and contact_default for oe_contact.
 */
#[Group('oe_ai_assistant')]
class DraftableNodeTypeSelectionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Drupal core.
    'datetime',
    'field',
    'file',
    'filter',
    'node',
    'options',
    'system',
    'text',
    'user',
    'workflows',
    'content_moderation',
    'serialization',
    'image',
    'link',
    'taxonomy',
    // Contrib.
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    // This project.
    'oe_ai_assistant',
    'state_machine',
    'document_loader',
    'document_loader_tika',
    'oe_ai_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['oe_ai_assistant_test']);
    $this->container->get('config.typed')->clearCachedDefinitions();
  }

  /**
   * Returns the handler instance under test.
   */
  private function handler() {
    return $this->container->get('plugin.manager.entity_reference_selection')
      ->getInstance(['target_type' => 'node_type', 'handler' => 'ai_draftable_node_type_selection']);
  }

  /**
   * Returns the referenceable node type IDs, sorted.
   *
   * @return string[]
   *   The node type IDs.
   */
  private function referenceableTypeIds(): array {
    $referenceable = $this->handler()->getReferenceableEntities();
    $ids = array_keys($referenceable['node_type'] ?? []);
    sort($ids);
    return $ids;
  }

  /**
   * Only bundles with an enabled template are referenceable.
   */
  public function testReturnsOnlyBundlesWithEnabledTemplate(): void {
    $this->assertSame(['oe_contact', 'oe_news'], $this->referenceableTypeIds());
  }

  /**
   * Disabling all templates of a bundle removes it from the result.
   */
  public function testDisablingAllBundleTemplatesRemovesIt(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_drafting_template');
    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $template */
    foreach ($storage->loadByProperties(['content_type' => 'oe_contact']) as $template) {
      $template->set('status', FALSE);
      $template->save();
    }

    $this->assertSame(['oe_news'], $this->referenceableTypeIds());
  }

  /**
   * No bundle is referenceable when no template is enabled.
   */
  public function testNoEnabledTemplateReturnsNothing(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_drafting_template');
    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $template */
    foreach ($storage->loadMultiple() as $template) {
      $template->set('status', FALSE);
      $template->save();
    }

    $this->assertSame([], $this->referenceableTypeIds());
  }

}
