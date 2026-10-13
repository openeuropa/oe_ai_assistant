<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use NeuronAI\Tools\Tool;

/**
 * Tool listing the drafts generated in the session being served.
 *
 * Each entry carries the "Draft M.m" name the editor sees and the provenance
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
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The editorial session whose drafts are listed.
   */
  public function __construct(
    private readonly DraftHistoryInterface $draftHistory,
    private readonly AiEditorialSessionInterface $session,
  ) {
    parent::__construct(
      self::NAME,
      'Returns the drafts generated in this session, one entry per version'
      . ' ("Draft 1.0", "Draft 2.0", a revision "Draft 1.1"), each with the'
      . ' tone, template and documents that produced it. Refer to drafts by'
      . ' these names; the user sees the same names.',
    );
  }

  /**
   * Returns the drafts of the session as JSON.
   */
  public function __invoke(): string {
    return json_encode(['drafts' => $this->draftHistory->listDrafts($this->session)]);
  }

}
