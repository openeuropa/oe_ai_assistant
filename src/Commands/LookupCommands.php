<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Commands;

use Consolidation\OutputFormatters\StructuredData\UnstructuredData;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for module lookup plugins.
 */
class LookupCommands extends DrushCommands {

  /**
   * Supported lookup types keyed by their required inputs.
   *
   * @var array<string, string[]>
   */
  private const REQUIRED_INPUTS = [
    'paragraphs' => ['entity_type', 'bundle', 'field_name'],
    'media' => ['entity_type', 'bundle', 'field_name'],
  ];

  /**
   * Supported lookup types keyed by function-call plugin name.
   *
   * @var array<string, string>
   */
  private const LOOKUP_FUNCTIONS = [
    'paragraphs' => 'lookup_paragraph_types',
    'media' => 'lookup_media',
  ];

  public function __construct(
    private readonly object $functionCallManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct();
  }

  /**
   * Executes a module lookup and prints its structured result.
   *
   * @param string $type
   *   The lookup type.
   * @param string $entity_type
   *   The host entity type.
   * @param string $bundle
   *   The host bundle.
   * @param string $field_name
   *   The reference field machine name on the host bundle.
   * @param array<string, mixed> $options
   *   Command options for the lookup execution.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\UnstructuredData
   *   The structured lookup result.
   */
  #[CLI\Command(name: 'oe-ai-assistant:lookup', aliases: ['oeaia:lookup'])]
  #[CLI\Argument(name: 'type', description: 'Lookup type: paragraphs or media.')]
  #[CLI\Argument(name: 'entity_type', description: 'Host entity type, for example node or paragraph.')]
  #[CLI\Argument(name: 'bundle', description: 'Host bundle.')]
  #[CLI\Argument(name: 'field_name', description: 'Reference field machine name on the host bundle.')]
  #[CLI\Option(name: 'query', description: 'Search text required for media lookups.')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of matches to return for media lookups.')]
  #[CLI\Option(name: 'uid', description: 'Drupal user ID used for access-checked lookup execution. Defaults to 1.')]
  #[CLI\Usage(name: 'drush oe-ai-assistant:lookup paragraphs node oe_news field_content_paragraphs --uid=1', description: 'List the paragraph bundles allowed by a paragraph reference field as user 1.')]
  #[CLI\Usage(name: 'drush oe-ai-assistant:lookup media node article field_media_assets --query=autumn --limit=5 --uid=1 --format=json', description: 'Search the media items allowed by a media reference field as user 1.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function lookup(
    string $type,
    string $entity_type,
    string $bundle,
    string $field_name,
    array $options = [
      'query' => NULL,
      'limit' => NULL,
      'uid' => NULL,
      'format' => 'json',
    ],
  ): UnstructuredData {
    $lookup = $this->normalizeLookupType($type);
    $inputs = [
      'entity_type' => trim($entity_type),
      'bundle' => trim($bundle),
      'field_name' => trim($field_name),
    ];
    $query = trim((string) ($options['query'] ?? ''));
    if ($query !== '') {
      $inputs['query'] = $query;
    }

    $limit = $options['limit'] ?? NULL;
    if (isset($limit)) {
      $validatedLimit = filter_var($limit, FILTER_VALIDATE_INT);
      if ($validatedLimit === FALSE) {
        throw new \InvalidArgumentException('The "limit" option must be an integer.');
      }
      $inputs['limit'] = $validatedLimit;
    }

    $missing = array_diff(self::REQUIRED_INPUTS[$lookup], array_keys($inputs));
    if ($missing !== []) {
      throw new \InvalidArgumentException(sprintf(
        'Missing required input(s): %s.',
        implode(', ', $missing),
      ));
    }
    $this->accountSwitcher->switchTo($this->resolveAccount($options));
    try {
      return new UnstructuredData(
        $this->executeLookup($lookup, $inputs),
      );
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Validates and normalizes a lookup type argument.
   *
   * @param string $lookup
   *   The lookup type.
   */
  private function normalizeLookupType(string $lookup): string {
    $lookup = trim($lookup);
    if (!isset(self::REQUIRED_INPUTS[$lookup])) {
      throw new \InvalidArgumentException(sprintf(
        'Unsupported lookup type "%s". Allowed values: %s.',
        $lookup,
        implode(', ', array_keys(self::REQUIRED_INPUTS)),
      ));
    }

    return $lookup;
  }

  /**
   * Executes the selected lookup plugin and returns its structured output.
   *
   * @param string $lookup
   *   The normalized lookup type.
   * @param array<string, mixed> $inputs
   *   The lookup plugin context values.
   *
   * @return array<string, mixed>
   *   The plugin structured output.
   */
  private function executeLookup(string $lookup, array $inputs): array {
    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName(self::LOOKUP_FUNCTIONS[$lookup]);

    if (!$plugin instanceof StructuredExecutableFunctionCallInterface) {
      throw new \LogicException(sprintf(
        'The %s plugin is not executable.',
        self::LOOKUP_FUNCTIONS[$lookup],
      ));
    }

    foreach ($inputs as $name => $value) {
      $plugin->setContextValue($name, $value);
    }

    $plugin->execute();

    return $plugin->getStructuredOutput();
  }

  /**
   * Resolves the account used for access-checked lookups.
   *
   * @param array $options
   *   The lookup plugin context values.
   */
  private function resolveAccount(array $options): AccountInterface {
    $uid = $options['uid'] ?? 1;
    if ($uid === NULL || $uid === '') {
      $uid = 1;
    }

    $validatedUid = filter_var($uid, FILTER_VALIDATE_INT);
    if ($validatedUid === FALSE || $validatedUid < 1) {
      throw new \InvalidArgumentException('The "uid" option must be a positive integer.');
    }

    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load($validatedUid);
    if (!$account instanceof AccountInterface) {
      throw new \InvalidArgumentException(sprintf(
        'The user with ID %d does not exist.',
        $validatedUid,
      ));
    }

    return $account;
  }

}
