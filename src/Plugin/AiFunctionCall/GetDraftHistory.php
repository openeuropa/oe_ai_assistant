<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * FunctionCall plugin that lists the generated drafts of a session.
 *
 * The LLM calls this tool to answer questions such as "which tone produced
 * Draft 2?". Each entry carries the Draft N name shared with the editor and
 * the provenance snapshot stored at generation time. The session id is
 * pinned server-side via fixed tool contexts, so the model cannot read
 * another session's history.
 */
#[FunctionCall(
  id: 'oe_ai_assistant:get_draft_history',
  function_name: 'get_draft_history',
  name: 'Get Draft History',
  description: 'Returns the drafts generated in this session, one entry per version ("Draft 1", "Draft 2", ...), each with the tone, template and documents that produced it.',
  context_definitions: [
    'session_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Session ID"),
      description: new TranslatableMarkup("The editorial session whose drafts to list."),
      required: TRUE,
    ),
  ],
)]
class GetDraftHistory extends FunctionCallBase implements StructuredExecutableFunctionCallInterface {

  /**
   * The draft history reader.
   *
   * @var \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface
   */
  protected DraftHistoryInterface $draftHistory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user, for the session access check.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The structured output from execute().
   *
   * @var array
   */
  protected array $output = [];

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): FunctionCallInterface|static {
    $instance = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );
    $instance->draftHistory = $container->get(DraftHistoryInterface::class);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $values = $this->getContextValues();
    $sessionId = (string) ($values['session_id'] ?? '');

    $session = $sessionId !== ''
      ? $this->entityTypeManager->getStorage('ai_editorial_session')->load($sessionId)
      : NULL;
    // The id is pinned by the fixed tool context, so a miss means a broken
    // setup rather than model input; still fail closed with an access check.
    if (!$session instanceof AiEditorialSessionInterface
      || !$session->access('view', $this->currentUser)
    ) {
      $this->output = ['error' => 'The editorial session is not available.'];
      return;
    }

    $this->output = ['drafts' => $this->draftHistory->listDrafts($session)];
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return json_encode($this->output, JSON_PRETTY_PRINT);
  }

  /**
   * {@inheritdoc}
   */
  public function getStructuredOutput(): array {
    return $this->output;
  }

}
