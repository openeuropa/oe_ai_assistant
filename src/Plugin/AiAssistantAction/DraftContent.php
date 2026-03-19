<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiAssistantAction;

use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Action plugin for drafting content.
 *
 * The LLM calls this tool with a complete set of field values matching the
 * content type schema. The plugin validates the fields and stores them so
 * they flow back to the frontend via AG-UI tool call result events.
 */
#[AiAssistantAction(
  id: 'oe_ai_draft_content',
  label: new TranslatableMarkup('Draft Content'),
)]
class DraftContent extends AiAssistantActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function listActions(): array {
    return [
      'draft_content' => [
        'id' => 'draft_content',
        'plugin' => 'oe_ai_draft_content',
        'label' => new TranslatableMarkup('Draft content fields'),
        'description' => new TranslatableMarkup(
          'Produce a complete set of field values for a content type.'
        ),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function triggerAction(string $action_id, $parameters = []): void {
    if ($action_id !== 'draft_content') {
      return;
    }

    $fields = $parameters['fields'] ?? $parameters;

    // Store the drafted fields so they flow back to the frontend.
    $this->setOutputContext('draft_content', json_encode($fields));
    $this->setStructuredResults('drafted_fields', $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function provideFewShotLearningExample(): array {
    return [
      [
        'description' => 'When asked to draft a news article about climate policy, call draft_content with all fields:',
        'schema' => [
          'actions' => [
            [
              'action' => 'draft_content',
              'plugin' => 'oe_ai_draft_content',
              'fields' => [
                'title' => 'New Climate Policy Framework',
                'body' => [
                  'value' => '<p>The new framework establishes...</p>',
                ],
                'field_teaser' => 'A landmark climate policy framework.',
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctionCallSchema(): array {
    return [
      'action' => [
        'type' => 'string',
        'description' => 'Must be "draft_content".',
        'enum' => ['draft_content'],
      ],
      'fields' => [
        'type' => 'object',
        'description' => 'Complete set of field values for the content type. '
          . 'Field names and value shapes must match the content type '
          . 'schema provided in the system prompt.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {}

}
