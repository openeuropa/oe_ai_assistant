<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Bundle;

use Drupal\oe_ai_assistant\Entity\AiEditorialSession;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use NeuronAI\Chat\History\MessageStoreInterface;

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
   *
   * The rows of the drafts thread carry no session reference, so the delete
   * of the parent cannot reach them. This clears the thread by name
   * first.
   */
  public function deleteConversation(): void {
    \Drupal::service(MessageStoreInterface::class)->clear($this->draftsThread());
    parent::deleteConversation();
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
    foreach ($this->draftResults() as $result) {
      if ((int) $result['version'] !== $version || self::hasFailed($result)) {
        continue;
      }

      return [
        'fields' => $result['fields'] ?? [],
        'templateId' => $result['context']['template']['id'] ?? NULL,
      ];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftRun(int $version): ?array {
    foreach ($this->draftResults() as $result) {
      if ((int) $result['version'] === $version) {
        return $result;
      }
    }

    return NULL;
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
    foreach (\Drupal::service(MessageStoreInterface::class)->loadAll($this->draftsThread()) as $message) {
      $draft = json_decode((string) $message->getContent(), TRUE);
      if (is_array($draft) && isset($draft['version'])) {
        $results[] = $draft;
      }
    }

    usort($results, static fn (array $a, array $b): int => (int) $a['version'] <=> (int) $b['version']);

    return $results;
  }

}
