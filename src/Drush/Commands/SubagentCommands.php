<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Drush\Commands;

use Drupal\oe_ai_assistant\Service\Subagent\SubagentDispatcher;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the background tasks of subagents.
 */
class SubagentCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly SubagentDispatcher $dispatcher,
  ) {
    parent::__construct();
  }

  /**
   * Queues the background tasks that no queue item is going to run.
   */
  #[CLI\Command(name: 'oe_ai_assistant:subagents-sweep', aliases: ['oe-ai:subagents:sweep'])]
  #[CLI\Usage(name: 'drush oe-ai:subagents:sweep', description: 'Queue the lost background tasks.')]
  public function sweep(): void {
    $this->output()->writeln((string) $this->dispatcher->sweep());
  }

}
