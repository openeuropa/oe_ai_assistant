<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use NeuronAI\Tools\Tool;

/**
 * Tool listing the drafts generated in the session being served.
 *
 * Each entry carries the "Draft N" name the editor sees and the provenance
 * snapshot stored at generation time. The session is pinned by the caller,
 * so the model cannot read another session's history.
 */
final class GetDraftHistoryTool extends Tool {

  public const NAME = 'get_draft_history';

  /**
   * GetDraftHistoryTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface $draftHistory
   *   The draft history reader.
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The editorial session whose drafts are listed.
   */
  public function __construct(
    private readonly DraftHistoryInterface $draftHistory,
    private readonly EntityInterface $session,
  ) {
    parent::__construct(
      self::NAME,
      'Returns the drafts generated in this session, one entry per version'
      . ' ("Draft 1", "Draft 2", ...), each with the tone, template and'
      . ' documents that produced it.',
    );
  }

  /**
   * Returns the drafts of the session as JSON.
   */
  public function __invoke(): string {
    return json_encode(['drafts' => $this->draftHistory->listDrafts($this->session)]);
  }

}
