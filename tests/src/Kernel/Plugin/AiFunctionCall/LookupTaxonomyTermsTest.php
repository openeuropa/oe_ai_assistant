<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\oe_ai_assistant\Commands\LookupCommands;
use Drupal\oe_ai_assistant\Plugin\AiFunctionCall\LookupTaxonomyTerms;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the lookup_taxonomy_terms function-call plugin.
 */
#[Group('oe_ai_assistant')]
#[RunTestsInSeparateProcesses]
final class LookupTaxonomyTermsTest extends KernelTestBase {

  /**
   * The enabled modules.
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'node',
    'taxonomy',
    'text',
    'key',
    'ai',
    'workflows',
    'content_moderation',
    'oe_ai_assistant',
  ];

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager
   */
  protected FunctionCallPluginManager $functionCallManager;

  /**
   * The current user proxy.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The created term IDs keyed by term name.
   *
   * @var array<string, int>
   */
  protected array $termIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('taxonomy_vocabulary');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'field',
      'filter',
      'node',
      'taxonomy',
    ]);

    $this->functionCallManager = $this->container
      ->get('plugin.manager.ai.function_calls');
    $this->currentUser = $this->container->get('current_user');

    NodeType::create([
      'type' => 'oe_news',
      'name' => 'OE News',
    ])->save();

    Vocabulary::create([
      'vid' => 'topics',
      'name' => 'Topics',
    ])->save();
    Vocabulary::create([
      'vid' => 'audiences',
      'name' => 'Audiences',
    ])->save();

    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'field_topics',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => [
        'target_type' => 'taxonomy_term',
      ],
    ]);
    $fieldStorage->save();

    FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => 'oe_news',
      'label' => 'Topics',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => [
            'topics' => 'topics',
          ],
        ],
      ],
    ])->save();

    $role = Role::create([
      'id' => 'oe_editor',
      'label' => 'OE Editor',
    ]);
    $role->grantPermission('create oe_news content');
    $role->grantPermission('access content');
    $role->save();

    $user = User::create([
      'name' => 'oe_editor',
      'mail' => 'oe_editor@example.com',
      'status' => 1,
      'roles' => ['oe_editor'],
    ]);
    $user->save();

    $this->currentUser->setAccount($user);

    $this->termIds['Climate Action'] = (int) $this
      ->createTerm('topics', 'Climate Action')
      ->id();
    $this->termIds['Climate Finance'] = (int) $this
      ->createTerm('topics', 'Climate Finance', $this->termIds['Climate Action'])
      ->id();
    $this->termIds['Energy Union'] = (int) $this
      ->createTerm('topics', 'Energy Union')
      ->id();
    $this->termIds['Climate Audience'] = (int) $this
      ->createTerm('audiences', 'Climate Audience')
      ->id();
  }

  /**
   * Tests plugin discovery and taxonomy lookup results.
   */
  #[Test]
  public function lookupTaxonomyTerms(): void {
    $this->assertTrue(
      $this->functionCallManager->functionExists('lookup_taxonomy_terms'),
    );

    $definition = $this->functionCallManager
      ->getDefinition('oe_ai_assistant:lookup_taxonomy_terms');
    $this->assertSame('oe_ai_assistant', $definition['group']);
    $this->assertSame('lookup_taxonomy_terms', $definition['function_name']);

    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName('lookup_taxonomy_terms');
    $this->assertInstanceOf(LookupTaxonomyTerms::class, $plugin);
    $this->assertInstanceOf(
      StructuredExecutableFunctionCallInterface::class,
      $plugin,
    );

    $plugin->setContextValue('entity_type', 'node');
    $plugin->setContextValue('bundle', 'oe_news');
    $plugin->setContextValue('field_name', 'field_topics');
    $plugin->setContextValue('query', 'Climate');
    $plugin->execute();

    $expected = [
      'taxonomy_terms' => [
        [
          'target_id' => $this->termIds['Climate Action'],
          'label' => 'Climate Action',
          'vocabulary' => 'topics',
          'parent' => 0,
        ],
        [
          'target_id' => $this->termIds['Climate Finance'],
          'label' => 'Climate Finance',
          'vocabulary' => 'topics',
          'parent' => $this->termIds['Climate Action'],
        ],
      ],
    ];

    $this->assertSame($expected, $plugin->getStructuredOutput());
    $this->assertSame(
      Json::encode($expected),
      $plugin->getReadableOutput(),
    );
  }

  /**
   * Tests that the optional limit reduces the result set.
   */
  #[Test]
  public function lookupTaxonomyTermsLimit(): void {
    $plugin = $this->functionCallManager
      ->getFunctionCallFromFunctionName('lookup_taxonomy_terms');

    $plugin->setContextValue('entity_type', 'node');
    $plugin->setContextValue('bundle', 'oe_news');
    $plugin->setContextValue('field_name', 'field_topics');
    $plugin->setContextValue('query', 'Climate');
    $plugin->setContextValue('limit', 1);
    $plugin->execute();

    $this->assertSame([
      'taxonomy_terms' => [
        [
          'target_id' => $this->termIds['Climate Action'],
          'label' => 'Climate Action',
          'vocabulary' => 'topics',
          'parent' => 0,
        ],
      ],
    ], $plugin->getStructuredOutput());
  }

  /**
   * Tests that the command executes the taxonomy lookup in-process.
   */
  #[Test]
  public function lookupTaxonomyTermsCommand(): void {
    $command = new LookupCommands(
      $this->functionCallManager,
      $this->container->get('entity_type.manager'),
      $this->container->get('account_switcher'),
    );

    $result = $command->lookup(
      'taxonomy',
      'node',
      'oe_news',
      'field_topics',
      [
        'uid' => '1',
        'query' => 'Climate',
        'limit' => '1',
      ],
    );

    $this->assertSame([
      'taxonomy_terms' => [
        [
          'target_id' => $this->termIds['Climate Action'],
          'label' => 'Climate Action',
          'vocabulary' => 'topics',
          'parent' => 0,
        ],
      ],
    ], $result->getArrayCopy());

    $result = $command->lookup(
      'taxonomy',
      'node',
      'oe_news',
      'field_topics',
    );

    $this->assertSame([
      'taxonomy_terms' => [
        [
          'target_id' => $this->termIds['Climate Action'],
          'label' => 'Climate Action',
          'vocabulary' => 'topics',
          'parent' => 0,
        ],
        [
          'target_id' => $this->termIds['Climate Finance'],
          'label' => 'Climate Finance',
          'vocabulary' => 'topics',
          'parent' => $this->termIds['Climate Action'],
        ],
        [
          'target_id' => $this->termIds['Energy Union'],
          'label' => 'Energy Union',
          'vocabulary' => 'topics',
          'parent' => 0,
        ],
      ],
    ], $result->getArrayCopy());
  }

  /**
   * Creates a published taxonomy term.
   *
   * @param string $vocabulary
   *   The vocabulary ID.
   * @param string $name
   *   The term label.
   * @param int $parentId
   *   The parent term ID.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   The saved term.
   */
  private function createTerm(
    string $vocabulary,
    string $name,
    int $parentId = 0,
  ): Term {
    $values = [
      'vid' => $vocabulary,
      'name' => $name,
      'status' => TRUE,
    ];

    if ($parentId > 0) {
      $values['parent'] = [$parentId];
    }

    $term = Term::create($values);
    $term->save();

    return $term;
  }

}
