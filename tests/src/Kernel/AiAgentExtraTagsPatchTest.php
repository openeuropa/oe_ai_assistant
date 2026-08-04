<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies the ai_agents patch keeps extra tags on the entity wrapper.
 *
 * The stock AiAgentEntityWrapper::setUserInterface() is a no-op and
 * getExtraTags() returns an empty array. The transcript capture relies on
 * correlation tags surviving on the wrapper, so this test guards the patch.
 *
 * @group oe_ai_assistant
 */
class AiAgentExtraTagsPatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Drupal core.
    'datetime',
    'field',
    'file',
    'filter',
    'media',
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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The oe_ai_assistant config ships the oe_content_drafter agent, whose
    // field storages target these entity types; install their schemas first.
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_editorial_session');
    $this->installConfig(['oe_ai_assistant']);
  }

  /**
   * Tests that setUserInterface extra tags survive to getExtraTags().
   */
  public function testExtraTagsSurviveOnEntityWrapper(): void {
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent */
    $agent = $this->container->get('plugin.manager.ai_agents')
      ->createInstance('oe_content_drafter');
    $agent->setUserInterface(NULL, ['oe_session:42', 'oe_agent:title']);
    $this->assertSame(['oe_session:42', 'oe_agent:title'], $agent->getExtraTags());
  }

}
