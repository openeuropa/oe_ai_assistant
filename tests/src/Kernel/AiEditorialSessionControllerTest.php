<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Controller\AiEditorialSessionController;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the drupalSettings config built for the session view page.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionControllerTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template');
    $storage->create([
      'id' => 'news_minimal',
      'label' => 'News (minimal)',
      'description' => 'Title only.',
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Headline.']],
    ])->save();
    $storage->create([
      'id' => 'news_disabled',
      'label' => 'News (disabled)',
      'description' => 'Disabled template.',
      'status' => FALSE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Headline.']],
    ])->save();
  }

  /**
   * Returns the controller under test.
   */
  private function controller(): AiEditorialSessionController {
    return $this->container->get('class_resolver')
      ->getInstanceFromDefinition(AiEditorialSessionController::class);
  }

  /**
   * Returns the drafting plugin config from a view() render array.
   */
  private function draftingConfig(array $build): array {
    return $build['#attached']['drupalSettings']['oeAiAssistant']['pluginConfig']['drafting'];
  }

  /**
   * The enabled templates for the session bundle are exposed to the app.
   */
  public function testViewExposesAvailableTemplates(): void {
    $session = $this->createSession($this->createUser());

    $drafting = $this->draftingConfig($this->controller()->view($session));

    $this->assertSame('node', $drafting['entityTypeId']);
    $this->assertSame('oe_news', $drafting['bundle']);
    $this->assertTrue($drafting['templates']['enabled']);
    $this->assertSame([
      [
        'id' => 'news_minimal',
        'label' => 'News (minimal)',
        'description' => 'Title only.',
      ],
    ], $drafting['templates']['options']);
  }

  /**
   * A bundle without templates yields an empty list without breaking init.
   */
  public function testViewWithoutTemplatesExposesEmptyList(): void {
    $session = $this->createContactSession();

    $drafting = $this->draftingConfig($this->controller()->view($session));

    $this->assertSame([], $drafting['templates']['options']);
  }

  /**
   * The render array is invalidated when templates change.
   */
  public function testViewCarriesTemplateCacheTags(): void {
    $session = $this->createSession($this->createUser());

    $build = $this->controller()->view($session);
    $tags = $build['#cache']['tags'] ?? [];

    // The list cache tag is invalidated on every template save, update and
    // delete, so it alone keeps availableTemplates fresh.
    $this->assertContains('config:ai_drafting_template_list', $tags);
  }

  /**
   * The saved template id is exposed for rehydration.
   */
  public function testViewExposesSelectedTemplate(): void {
    $session = $this->createSession($this->createUser());
    $session->set('template', 'news_minimal')->save();

    $drafting = $this->draftingConfig($this->controller()->view($session));

    $this->assertSame('news_minimal', $drafting['templates']['selected']);
  }

  /**
   * With no selection the exposed template is an empty string.
   */
  public function testViewWithoutSelectionExposesEmptyTemplate(): void {
    $session = $this->createSession($this->createUser());

    $drafting = $this->draftingConfig($this->controller()->view($session));

    $this->assertSame('', $drafting['templates']['selected']);
  }

  /**
   * The render array is invalidated when the session (its template) changes.
   */
  public function testViewCarriesSessionCacheTag(): void {
    $session = $this->createSession($this->createUser());

    $build = $this->controller()->view($session);

    $this->assertContains('ai_editorial_session:' . $session->id(), $build['#cache']['tags'] ?? []);
  }

  /**
   * The page varies per user because drupalSettings embeds the user id.
   */
  public function testViewVariesByUser(): void {
    $session = $this->createSession($this->createUser());

    $build = $this->controller()->view($session);

    $this->assertContains('user', $build['#cache']['contexts'] ?? []);
  }

  /**
   * Creates a session targeting the template-less oe_contact bundle.
   */
  private function createContactSession(): AiEditorialSessionInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $this->createUser()->id(),
        'content_type' => 'oe_contact',
      ]);
    $session->save();

    return $session;
  }

}
