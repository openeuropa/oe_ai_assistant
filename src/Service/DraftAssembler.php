<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validates, merges template defaults, and builds an unsaved draft node.
 */
class DraftAssembler implements DraftAssemblerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    private readonly DraftEntityBuilder $draftEntityBuilder,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assemble(string $bundle, array $fields, ?string $templateId, ?ContentEntityInterface $existingNode = NULL): ContentEntityInterface {
    if ($existingNode === NULL) {
      if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
        throw new ActionException('invalid_bundle',
          sprintf('Content type "%s" does not exist.', $bundle), 400);
      }

      if (!$this->currentUser->hasPermission("create $bundle content")) {
        throw new ActionException(
          'forbidden',
          sprintf('You do not have permission to create %s content.', $bundle),
          403,
        );
      }
    }
    elseif (!$existingNode->access('update', $this->currentUser)) {
      throw new ActionException(
        'forbidden',
        'You do not have permission to update this node.',
        403,
      );
    }

    $mergedFields = $fields;
    if ($templateId !== NULL && $templateId !== '') {
      try {
        $template = $this->schemaProvider->resolveTemplate('node', $bundle, $templateId);
      }
      catch (\InvalidArgumentException $e) {
        throw new ActionException('invalid_request', $e->getMessage(), 400);
      }
      if ($template !== NULL) {
        // resolveDefaults() mirrors the raw config shape defined by
        // oe_ai_assistant.ai_drafting_template_default in
        // config/schema/oe_ai_assistant.schema.yml: each field's value list
        // is wrapped in a 'default_value' key. Unwrap it here so the merged
        // map is a flat field-name => value-list map, the shape
        // fromLlmFields() expects.
        $resolvedDefaults = array_map(
          static fn (array $default) => $default['default_value'],
          $template->resolveDefaults(),
        );
        // Drafted fields win on collision: template fields/defaults are
        // disjoint by the template's own validation, but stay defensive.
        $mergedFields = $fields + $resolvedDefaults;
      }
    }

    try {
      $built = $this->draftEntityBuilder->fromLlmFields('node', $bundle, $mergedFields);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to build draft entity: @e', ['@e' => (string) $e]);
      throw new ActionException(
        'invalid_payload',
        'The submitted draft payload could not be processed. See the system log for details.',
        400,
      );
    }

    if ($existingNode === NULL) {
      return $built;
    }

    // Transplant only the merged fields' values onto the existing node, the
    // same field-by-field idiom core's EntityResource::patch() uses for
    // updates, so fields outside this draft are left untouched.
    foreach (array_keys($mergedFields) as $fieldName) {
      $existingNode->set($fieldName, $built->get($fieldName)->getValue());
    }
    return $existingNode;
  }

}
