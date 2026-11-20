<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupRunner;
use Drupal\oe_ai_assistant\Service\Subagent\TaskAccount;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drafts the field groups of a draft in parallel.
 */
#[QueueWorker(
  id: DraftGroupQueueWorker::QUEUE,
  title: new TranslatableMarkup('Draft field groups'),
)]
final class DraftGroupQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The queue, and the id of the worker plugin that reads it.
   */
  public const string QUEUE = 'oe_ai_assistant_draft_group';

  /**
   * How often the claim is renewed while the group is drafted, in seconds.
   */
  private const int RENEW_EVERY = 30;

  /**
   * What the editor reads when the group fails for a reason that is not theirs.
   */
  private const string FAILURE = 'The group could not be drafted.';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DraftGroupJobs $jobs,
    private readonly DraftGroupRunner $runner,
    private readonly TaskAccount $taskAccount,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(DraftGroupJobs::class),
      $container->get(DraftGroupRunner::class),
      $container->get(TaskAccount::class),
      $container->get('entity_type.manager'),
      $container->get('account_switcher'),
      $container->get('state'),
      $container->get('datetime.time'),
      $container->get('logger.channel.oe_ai_assistant'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // The queue keeps the item, so the job runs once the worker is resumed.
    if ($this->state->get(SubagentTaskQueueWorker::PAUSED_STATE)) {
      throw new SuspendQueueException('The subagent worker is paused.');
    }

    $jobId = (string) $data['job_id'];
    $worker = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    $row = $this->jobs->claim($jobId, $worker);
    if ($row === NULL) {
      return;
    }

    $session = $this->entityTypeManager->getStorage('ai_editorial_session')->load($row['session_id']);
    if (!$session instanceof ContentCreationSessionInterface) {
      $this->jobs->finish($jobId, $worker, DraftGroupJobs::FAILED, NULL, self::FAILURE);

      return;
    }

    // The account is checked again, because it may have lost its access
    // since the job was recorded.
    $user = $this->taskAccount->resolve($row['uid'], $session);
    if ($user === NULL) {
      $this->logger->warning('Draft group job @job is not run, because its user can no longer run it.', ['@job' => $jobId]);
      $this->jobs->finish($jobId, $worker, DraftGroupJobs::FAILED, NULL, 'The user of the task can no longer run it.');

      return;
    }

    $this->accountSwitcher->switchTo($user);
    try {
      $input = $row['input'];
      $lastRenewal = $this->time->getCurrentTime();
      $drafting = $this->runner->run($session, $row['group_id'], (array) ($input['schema'] ?? []), (string) ($input['task'] ?? ''), $row['run']);
      foreach ($drafting as $progress) {
        $this->jobs->progress($jobId, $worker, $progress);
        $now = $this->time->getCurrentTime();
        if ($now - $lastRenewal < self::RENEW_EVERY) {
          continue;
        }
        // A renewal that fails means the lease lapsed and another worker
        // holds the job now, so this one stops without writing anything.
        if (!$this->jobs->renew($jobId, $worker)) {
          return;
        }
        $lastRenewal = $now;
      }
      $this->jobs->finish($jobId, $worker, DraftGroupJobs::DONE, (array) $drafting->getReturn());
    }
    catch (SchemaRetriesExhaustedException $e) {
      // The message only names template fields, so the editor may read it.
      $this->jobs->finish($jobId, $worker, DraftGroupJobs::FAILED, NULL, $e->getMessage());
    }
    catch (\Throwable $e) {
      $this->logger->error('Draft group job @job failed: @message', [
        '@job' => $jobId,
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);
      $this->jobs->finish($jobId, $worker, DraftGroupJobs::FAILED, NULL, self::FAILURE);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
