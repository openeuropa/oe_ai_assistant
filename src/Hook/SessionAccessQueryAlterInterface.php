<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Database\Query\AlterableInterface;

/**
 * Lets other hook classes reuse the session access query alter.
 */
interface SessionAccessQueryAlterInterface {

  /**
   * Restricts a query, aliased 'base_table', to sessions the user may access.
   */
  public function queryAiEditorialSessionAccessAlter(AlterableInterface $query): void;

}
