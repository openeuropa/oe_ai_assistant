<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Add/edit form for AI Drafting Templates.
 *
 * The 'fields' and 'defaults' properties are stored as PHP arrays in config
 * but presented as YAML textareas in the form. On submit the YAML is parsed
 * and the arrays are stored on the entity via copyFormValuesToEntity().
 */
final class AiDraftingTemplateForm extends EntityForm {

  public function __construct(
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $template */
    $template = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $template->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $template->id(),
      '#machine_name' => [
        'exists' => [AiDraftingTemplate::class, 'load'],
      ],
      '#disabled' => !$template->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $template->status(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
      '#default_value' => $template->getDescription(),
      '#description' => $this->t('Describe when this template should be used.'),
    ];

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => array_map(fn(array $info) => $info['label'], $this->entityTypeBundleInfo->getBundleInfo('node')),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $template->getContentType(),
      '#required' => TRUE,
    ];

    $form['fields_yaml'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fields'),
      '#rows' => 20,
      '#default_value' => $this->arrayToYaml($template->getFields()),
      '#description' => $this->t(
        'YAML map of field definitions. Scalar fields require a <code>prompt</code> key. Paragraph/entity-reference fields require <code>type</code> and <code>items</code>.'
      ),
    ];

    $form['defaults_yaml'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Defaults'),
      '#rows' => 6,
      '#default_value' => $this->arrayToYaml($template->getDefaults()),
      '#description' => $this->t(
        'YAML map of default field values applied by the orchestrator. Use <code>__NOW__</code> for the current date/time.'
      ),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Parse YAML before parent::validateForm() calls buildEntity(), which
    // triggers copyFormValuesToEntity() and unsets these values from form state.
    $fieldsArray = $this->parseYamlField($form_state, 'fields_yaml', 'Fields');
    $defaultsArray = $this->parseYamlField($form_state, 'defaults_yaml', 'Defaults');

    if ($form_state->hasAnyErrors()) {
      return;
    }

    // Stash parsed arrays now so copyFormValuesToEntity() can apply them.
    $form_state->set('parsed_fields', $fieldsArray ?? []);
    $form_state->set('parsed_defaults', $defaultsArray ?? []);

    parent::validateForm($form, $form_state);

    if ($form_state->hasAnyErrors()) {
      return;
    }

    // afterBuild() ran before validateForm() with parsed_fields/defaults absent
    // from form state, so $this->entity still has empty fields/defaults. Rebuild
    // it now so validateTemplate() receives the correct submitted values.
    $this->entity = $this->buildEntity($form, $form_state);

    // Run structural validation on the fully-built entity.
    $result = $this->entity->validate();
    foreach ($result->getErrors('content_type') as $error) {
      $form_state->setErrorByName('content_type', $error);
    }
    foreach ($result->getErrors('defaults') as $error) {
      $form_state->setErrorByName('defaults_yaml', $error);
    }
    foreach ($result->getErrors('fields') as $error) {
      $form_state->setErrorByName('fields_yaml', $error);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Unsets the raw YAML keys so parent does not try to copy them directly onto
   * the entity, then applies the pre-parsed arrays from form state.
   */
  protected function copyFormValuesToEntity(object $entity, array $form, FormStateInterface $form_state): void {
    $form_state->unsetValue('fields_yaml');
    $form_state->unsetValue('defaults_yaml');

    parent::copyFormValuesToEntity($entity, $form, $form_state);

    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $entity */
    $entity->set('fields', $form_state->get('parsed_fields') ?? []);
    $entity->set('defaults', $form_state->get('parsed_defaults') ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $messageArgs = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created AI drafting template %label.', $messageArgs),
        \SAVED_UPDATED => $this->t('Updated AI drafting template %label.', $messageArgs),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Parses a YAML textarea value and stores it in form state.
   *
   * Returns the parsed array, or NULL if parsing failed (error already set).
   */
  private function parseYamlField(FormStateInterface $form_state, string $element, string $label): ?array {
    // Use getUserInput() instead of getValue() because copyFormValuesToEntity()
    // (called from EntityForm::afterBuild() before validateForm()) unsets these
    // keys from the processed values. getUserInput() holds the raw POST data
    // and is unaffected by unsetValue().
    $raw = trim((string) ($form_state->getUserInput()[$element] ?? ''));
    if ($raw === '') {
      return [];
    }
    try {
      $parsed = Yaml::parse($raw);
    }
    catch (ParseException $e) {
      $form_state->setErrorByName($element, $this->t('@label: invalid YAML — @message', [
        '@label' => $label,
        '@message' => $e->getMessage(),
      ]));
      return NULL;
    }
    if (!is_array($parsed)) {
      $form_state->setErrorByName($element, $this->t('@label: YAML must be a mapping, not a scalar.', ['@label' => $label]));
      return NULL;
    }
    return $parsed;
  }

  /**
   * Returns a YAML string for an array, or empty string for empty input.
   */
  private function arrayToYaml(array $data): string {
    return empty($data) ? '' : Yaml::dump($data, 10, 2);
  }

}
