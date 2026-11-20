<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentDelivery;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentDispatcher;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentPresence;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delivers the results of background tasks to the editorial conversation.
 */
#[QueueWorker(
  id: SubagentDispatcher::DELIVERY_QUEUE,
  title: new TranslatableMarkup('Background subagent deliveries'),
  cron: ['time' => 30],
)]
final class SubagentDeliveryQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * How long a fresh result waits for a browser to deliver it, in seconds.
   */
  private const int BROWSER_GRACE = 60;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SubagentDelivery $delivery,
    private readonly SubagentPresence $presence,
    private readonly SubagentTaskLedger $ledger,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly TimeInterface $time,
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
      $container->get(SubagentDelivery::class),
      $container->get(SubagentPresence::class),
      $container->get(SubagentTaskLedger::class),
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('account_switcher'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // The queue keeps the item, so the delivery runs once the worker resumes.
    if ($this->state->get(SubagentTaskQueueWorker::PAUSED_STATE)) {
      throw new SuspendQueueException('The subagent worker is paused.');
    }

    $sessionId = (int) $data['session_id'];
    $session = $this->entityTypeManager->getStorage('ai_editorial_session')->load($sessionId);
    if (!$session instanceof ContentCreationSessionInterface) {
      return;
    }

    // A browser that polls delivers the results live, in front of the
    // editor. The job only steps in once the results have waited too long.
    $oldest = $this->ledger->oldestWaiting($sessionId);
    if ($oldest !== NULL
      && $this->presence->isPresent($sessionId)
      && $this->time->getCurrentTime() - $oldest < self::BROWSER_GRACE) {
      throw new DelayedRequeueException(5);
    }

    // The turn runs for the owner of the session, as a request of theirs would.
    $owner = $session->getOwner();
    $this->accountSwitcher->switchTo($owner ?? new AnonymousUserSession());
    try {
      $outcome = $this->delivery->deliver($session);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    if ($outcome === SubagentDelivery::BUSY) {
      throw new DelayedRequeueException(3);
    }
  }

}
