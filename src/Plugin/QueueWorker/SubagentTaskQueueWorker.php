<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs the background tasks of subagents.
 */
#[QueueWorker(
  id: 'oe_ai_assistant_subagent_task',
  title: new TranslatableMarkup('Background subagent tasks'),
  cron: ['time' => 60],
)]
final class SubagentTaskQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The state key that pauses the worker while it is truthy.
   */
  public const string PAUSED_STATE = 'oe_ai_assistant.subagent_worker_paused';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SubagentRunner $runner,
    private readonly StateInterface $state,
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
      $container->get(SubagentRunner::class),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // The queue keeps the item, so the task runs once the worker is resumed.
    if ($this->state->get(self::PAUSED_STATE)) {
      throw new SuspendQueueException('The subagent worker is paused.');
    }

    $this->runner->run((string) $data['task_id']);
  }

}
