<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\ReferenceFieldResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists taxonomy terms allowed by a specific taxonomy reference field.
 */
#[FunctionCall(
  id: 'oe_ai_assistant:lookup_taxonomy_terms',
  function_name: 'lookup_taxonomy_terms',
  name: 'Lookup Taxonomy Terms',
  description: 'Returns accessible taxonomy terms allowed by a specific taxonomy reference field on a host bundle. Use this before drafting taxonomy term references.',
  group: 'oe_ai_assistant',
  module_dependencies: ['taxonomy'],
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Entity type"),
      description: new TranslatableMarkup("The host entity type that owns the field, for example node or paragraph."),
      required: TRUE,
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Bundle"),
      description: new TranslatableMarkup("The host bundle that owns the field."),
      required: TRUE,
    ),
    'field_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Field name"),
      description: new TranslatableMarkup("The taxonomy reference field machine name on the host bundle."),
      required: TRUE,
    ),
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Query"),
      description: new TranslatableMarkup("Search text used to match taxonomy term labels."),
      required: FALSE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup("Limit"),
      description: new TranslatableMarkup("Maximum number of matches to return."),
      required: FALSE,
      default_value: 10,
    ),
  ],
)]
class LookupTaxonomyTerms extends FunctionCallBase implements StructuredExecutableFunctionCallInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The reference field resolver.
   *
   * @var \Drupal\oe_ai_assistant\Service\ReferenceFieldResolver
   */
  protected ReferenceFieldResolver $referenceFieldResolver;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Loads plugin dependencies from the container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    /** @var static $instance */
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
    $instance->currentUser = $container->get('current_user');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->referenceFieldResolver = $container->get(ReferenceFieldResolver::class);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entityTypeId = $this->getContextValue('entity_type');
    $bundle = $this->getContextValue('bundle');
    $fieldName = $this->getContextValue('field_name');
    $searchText = trim((string) $this->getContextValue('query'));
    $limit = (int) $this->getContextValue('limit');

    if ($limit < 1) {
      throw new \InvalidArgumentException('The "limit" value must be greater than 0.');
    }

    $access = $this->entityTypeManager
      ->getAccessControlHandler($entityTypeId)
      ->createAccess($bundle, $this->currentUser, [], TRUE);
    if (!$access->isAllowed()) {
      throw new \RuntimeException(sprintf(
        'The current user does not have create access to %s bundle "%s".',
        $entityTypeId,
        $bundle,
      ));
    }

    $fieldDefinition = $this->referenceFieldResolver->resolveFieldDefinition(
      $entityTypeId,
      $bundle,
      $fieldName,
      ['entity_reference'],
      'taxonomy_term',
    );

    $termDefinition = $this->entityTypeManager->getDefinition('taxonomy_term');
    $bundleKey = $termDefinition->getKey('bundle');
    $labelKey = $termDefinition->getKey('label');

    if (!is_string($bundleKey) || !is_string($labelKey)) {
      throw new \LogicException('The taxonomy term entity type must define label and bundle keys.');
    }

    $vocabularyIds = $this->referenceFieldResolver
      ->getAllowedTargetBundles($fieldDefinition);

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort($labelKey, 'ASC')
      ->range(0, $limit);
    if ($searchText !== '') {
      $query->condition(
        $labelKey,
        '%' . $this->database->escapeLike($searchText) . '%',
        'LIKE',
      );
    }
    if ($vocabularyIds !== []) {
      $query->condition($bundleKey, $vocabularyIds, 'IN');
    }

    $termIds = $query->execute();
    $entities = $storage->loadMultiple($termIds);

    $matches = [];
    foreach ($entities as $entity) {
      if (!$entity->access('view', $this->currentUser)) {
        continue;
      }

      $matches[] = [
        'target_id' => (int) $entity->id(),
        'label' => $entity->label(),
        'vocabulary' => $entity->bundle(),
        'parent' => (int) ($entity->get('parent')->target_id ?? 0),
      ];
    }

    $this->setStructuredOutput([
      'taxonomy_terms' => $matches,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return Json::encode($this->getStructuredOutput());
  }

}
