<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;

/**
 * Base class for the tools of one drafting run.
 *
 * The session is the only thing a caller supplies, and everything else a tool
 * reads follows from it. The context is required, so a tool built without a
 * session is refused rather than failing part way through a call.
 */
abstract class DraftingToolBase extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly DraftingBriefInterface $brief,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Shapes what the editor set up into the snapshot stored on a draft.
   *
   * Ids travel with the labels read at request time, so a draft keeps what the
   * editor saw even if a term or a template is renamed later. The file name
   * and the extracted text stay out: a draft records which documents were
   * used, not their contents.
   *
   * @param array|null $tone
   *   The tone the editor selected, or NULL when none is.
   * @param array|null $template
   *   The template the editor selected, or NULL when none is.
   * @param array $documents
   *   The documents the editor attached.
   *
   * @return array
   *   The snapshot.
   */
  public static function snapshot(?array $tone, ?array $template, array $documents): array {
    return [
      'tone' => $tone,
      'template' => $template,
      'documents' => array_map(static function (array $document): array {
        unset($document['filename'], $document['extract']);
        return $document;
      }, $documents),
    ];
  }

  /**
   * The entity type and bundle the run drafts for.
   *
   * @return array
   *   The entity type id and the bundle, in that order.
   */
  protected function target(): array {
    return [
      DraftingBriefInterface::ENTITY_TYPE_ID,
      $this->getContextValue('session')->getContentType(),
    ];
  }

  /**
   * What the editor set up, as a draft of this run records it.
   *
   * @return array
   *   The snapshot, as snapshot() shapes it.
   */
  protected function draftContext(): array {
    $session = $this->getContextValue('session');

    return self::snapshot(
      $this->brief->tone($session),
      $this->brief->template($session),
      $this->brief->documents($session),
    );
  }

}
