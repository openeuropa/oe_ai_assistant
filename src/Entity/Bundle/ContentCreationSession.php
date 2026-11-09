<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Bundle;

use Drupal\ai_neuron\Entity\NeuronMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSession;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;

/**
 * Bundle class for the content creation session.
 *
 * It answers what the session drafts with, and what it drafted so far. The
 * agents that prompt a model and the tools that answer the model both ask, so
 * the reading is here rather than in either.
 */
class ContentCreationSession extends AiEditorialSession implements ContentCreationSessionInterface {

  /**
   * What the editor set up, read on first use.
   */
  protected ?array $brief = NULL;

  /**
   * {@inheritdoc}
   */
  public function draftsThread(): string {
    return 'drafts.session_' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getTone(): ?array {
    return $this->brief()['tone'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTemplate(): ?array {
    return $this->brief()['template'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDocuments(): array {
    return $this->brief()['documents'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldGroups(): array {
    return $this->brief()['groups'];
  }

  /**
   * {@inheritdoc}
   */
  public function countDrafts(): int {
    return count($this->draftResults());
  }

  /**
   * {@inheritdoc}
   *
   * Two paths write drafts of one session, and each numbers its own. A count
   * would hand the editor a number they already saw, so the next version
   * follows the highest one stored.
   */
  public function nextVersion(): array {
    $versions = array_map(static fn (array $result): int => (int) $result['version'], $this->draftResults());

    return ['version' => ($versions === [] ? 0 : max($versions)) + 1];
  }

  /**
   * {@inheritdoc}
   */
  public function listDrafts(): array {
    $drafts = [];
    foreach ($this->draftResults() as $result) {
      if (self::hasFailed($result)) {
        continue;
      }
      $version = (int) $result['version'];
      $drafts[] = [
        'name' => sprintf('Draft %d', $version),
        'version' => $version,
        'context' => $result['context'],
      ];
    }

    return $drafts;
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftContent(int $version): ?array {
    $result = $this->draftResult($version);
    if ($result === NULL || self::hasFailed($result)) {
      return NULL;
    }

    return [
      'fields' => $result['fields'] ?? [],
      'templateId' => $result['context']['template']['id'] ?? NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftRun(int $version): ?array {
    return $this->draftResult($version);
  }

  /**
   * Says whether a stored record is a run that failed.
   *
   * Records written before the status was stored hold a draft, so a record
   * with no status is a run that worked.
   *
   * @param array $result
   *   The stored record.
   *
   * @return bool
   *   TRUE when the run failed.
   */
  private static function hasFailed(array $result): bool {
    return ($result['status'] ?? 'drafted') === 'failed';
  }

  /**
   * Reads what the editor set up, once.
   *
   * This reads the labels with the ids, so a draft records what the editor
   * saw even if someone renames a term or a template later. One reading also
   * tells every reader of this session the same thing.
   *
   * @return array
   *   The tone, the template, the documents and the groups.
   *
   * @throws \InvalidArgumentException
   *   When the stored template or tone is not one the bundle can draft with.
   */
  private function brief(): array {
    if ($this->brief !== NULL) {
      return $this->brief;
    }

    $schemaProvider = \Drupal::service(DraftingSchemaProviderInterface::class);
    $bundle = $this->getContentType();
    $template = $schemaProvider->resolveTemplate(
      self::ENTITY_TYPE_ID,
      $bundle,
      (string) $this->get('template')->target_id,
    );
    $toneId = (string) $this->get('tone')->target_id;

    return $this->brief = [
      'tone' => $toneId === ''
        ? NULL
        : \Drupal::service(AiEditorialContextInterface::class)->getTone($toneId),
      'template' => $template === NULL ? NULL : ['id' => $template->id(), 'label' => $template->label()],
      'documents' => \Drupal::service(ContextDocumentRepository::class)->describe($this),
      'groups' => $schemaProvider->groups(self::ENTITY_TYPE_ID, $bundle, $template?->id()),
    ];
  }

  /**
   * Collects every stored draft of this session, oldest first.
   *
   * The drafting run writes one record per run, which a run that failed
   * writes too. So the callers that hand a draft to the editor drop the
   * failed ones, and the ones that report what happened keep them.
   *
   * @return array
   *   One entry per run, each {version, run, status, context, groups,
   *   fields}.
   */
  private function draftResults(): array {
    $results = [];
    foreach ($this->conversationThread($this->draftsThread()) as $row) {
      $draft = self::decodeDraft($row);
      if ($draft !== NULL) {
        $results[] = $draft;
      }
    }

    usort($results, static fn (array $a, array $b): int => (int) $a['version'] <=> (int) $b['version']);

    return $results;
  }

  /**
   * Reads the record of one run, by the version the run took.
   *
   * @param int $version
   *   The version to read.
   *
   * @return array|null
   *   The record, or NULL when the session stored no such version.
   */
  private function draftResult(int $version): ?array {
    $rows = \Drupal::entityTypeManager()->getStorage('neuron_message')->loadByProperties([
      'oe_ai_session' => (int) $this->id(),
      'thread_id' => $this->draftsThread(),
      'oe_draft_version' => (string) $version,
    ]);
    $row = reset($rows);

    return $row === FALSE ? NULL : self::decodeDraft($row);
  }

  /**
   * Decodes the record a row of the drafts thread holds.
   *
   * @param \Drupal\ai_neuron\Entity\NeuronMessageInterface $row
   *   The stored message.
   *
   * @return array|null
   *   The record, or NULL when the row holds no draft.
   */
  private static function decodeDraft(NeuronMessageInterface $row): ?array {
    // The run writes the record as one JSON string. The row therefore holds
    // one text block.
    $draft = json_decode((string) ($row->getContent()[0]['content'] ?? ''), TRUE);

    return is_array($draft) && isset($draft['version']) ? $draft : NULL;
  }

}
