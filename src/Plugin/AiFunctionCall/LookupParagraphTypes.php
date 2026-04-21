<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\ReferenceFieldResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists paragraph bundles allowed by a specific paragraph reference field.
 */
#[FunctionCall(
  id: 'oe_ai_assistant:lookup_paragraph_types',
  function_name: 'lookup_paragraph_types',
  name: 'Lookup Paragraph Types',
  description: 'Returns the paragraph types allowed by a specific paragraph reference field on a host bundle. Use this before drafting entity reference revisions data.',
  group: 'oe_ai_assistant',
  module_dependencies: ['paragraphs', 'entity_reference_revisions'],
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
      description: new TranslatableMarkup("The paragraph reference field machine name on the host bundle."),
      required: TRUE,
    ),
  ],
)]
class LookupParagraphTypes extends FunctionCallBase implements StructuredExecutableFunctionCallInterface {

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
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The reference field resolver.
   *
   * @var \Drupal\oe_ai_assistant\Service\ReferenceFieldResolver
   */
  protected ReferenceFieldResolver $referenceFieldResolver;

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
    $instance->entityTypeBundleInfo = $container->get('entity_type.bundle.info');
    $instance->referenceFieldResolver = $container->get(ReferenceFieldResolver::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entityTypeId = $this->getContextValue('entity_type');
    $bundle = $this->getContextValue('bundle');
    $fieldName = $this->getContextValue('field_name');

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
      ['entity_reference_revisions'],
      'paragraph',
    );

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    $bundleIds = $this->referenceFieldResolver
      ->getAllowedTargetBundles($fieldDefinition);

    if ($bundleIds === []) {
      $bundleIds = array_keys($bundleInfo);
      usort($bundleIds, static function (string $left, string $right) use ($bundleInfo): int {
        $leftLabel = (string) ($bundleInfo[$left]['label'] ?? $left);
        $rightLabel = (string) ($bundleInfo[$right]['label'] ?? $right);
        return strnatcasecmp($leftLabel, $rightLabel);
      });
    }

    $paragraphTypes = [];
    foreach ($bundleIds as $bundleId) {
      $paragraphTypes[] = [
        'bundle' => $bundleId,
        'label' => (string) ($bundleInfo[$bundleId]['label'] ?? $bundleId),
      ];
    }

    $this->setStructuredOutput([
      'paragraph_types' => $paragraphTypes,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return Json::encode($this->getStructuredOutput());
  }

}
