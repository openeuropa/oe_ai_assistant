<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the add form scopes template options to the chosen content type.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionAddFormTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template');
    $storage->create([
      'id' => 'news_x',
      'label' => 'News X',
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
    $storage->create([
      'id' => 'contact_x',
      'label' => 'Contact X',
      'content_type' => 'oe_contact',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
  }

  /**
   * Returns the template select options when the given content type is chosen.
   */
  private function templateOptions(string $contentType): array {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $entity = $entityTypeManager->getStorage('ai_editorial_session')
      ->create(['type' => 'content_creation']);
    $formObject = $entityTypeManager->getFormObject('ai_editorial_session', 'add');
    $formObject->setEntity($entity);

    $formState = new FormState();
    $formState->setUserInput(['content_type' => $contentType]);
    $form = $this->container->get('form_builder')
      ->buildForm($formObject, $formState);

    return array_keys($form['template']['widget']['#options'] ?? []);
  }

  /**
   * The template options are the enabled templates of the chosen content type.
   */
  public function testTemplateOptionsFollowSelectedContentType(): void {
    $options = $this->templateOptions('oe_news');

    $this->assertContains('news_x', $options);
    $this->assertNotContains('contact_x', $options);
  }

  /**
   * The add form displays the configured transparency notice and its cache tag.
   */
  public function testTransparencyNotice(): void {
    $notice = '<strong>AI-generated content</strong>. <a href="https://example.com">Read more</a>.';
    $this->config('oe_ai_assistant.settings')
      ->set('transparency_notice', $notice)
      ->save();

    $entityTypeManager = $this->container->get('entity_type.manager');
    $entity = $entityTypeManager->getStorage('ai_editorial_session')
      ->create(['type' => 'content_creation']);
    $formObject = $entityTypeManager->getFormObject('ai_editorial_session', 'add');
    $formObject->setEntity($entity);

    $formState = new FormState();
    $form = $this->container->get('form_builder')
      ->buildForm($formObject, $formState);

    $this->assertSame($notice, $form['transparency_notice']['content']['#markup']);
    $this->assertContains('config:oe_ai_assistant.settings', $form['#cache']['tags'] ?? []);
  }

}
