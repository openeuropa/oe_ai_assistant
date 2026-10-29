<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Immutable set of optional filters applied to usage dashboard queries.
 *
 * The dashboard filter form collects a content type (a node bundle) and a
 * participant (an author user ID); either may be empty, meaning "do not
 * filter on that dimension". The provenance records store the author directly
 * but not the node bundle, so the content type is resolved to node IDs where
 * it is applied.
 */
final class DashboardFilters {

  /**
   * Constructs the filter set.
   *
   * @param string|null $contentType
   *   The node bundle machine name to filter on, or NULL for all types.
   * @param string|null $participant
   *   The author user ID to filter on, or NULL for all users.
   */
  public function __construct(
    public readonly ?string $contentType = NULL,
    public readonly ?string $participant = NULL,
  ) {}

  /**
   * Whether any filter is set.
   *
   * @return bool
   *   TRUE when at least one dimension is filtered.
   */
  public function isActive(): bool {
    return $this->contentType !== NULL || $this->participant !== NULL;
  }

}
