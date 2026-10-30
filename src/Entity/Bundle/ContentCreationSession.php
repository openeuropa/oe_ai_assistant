<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Bundle;

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
   */
  public function nextVersion(): array {
    return ['version' => $this->countDrafts() + 1];
  }

  /**
   * {@inheritdoc}
   */
  public function listDrafts(): array {
    $drafts = [];
    foreach ($this->draftResults() as $result) {
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
      if ((int) $result['version'] !== $version) {
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
   * Collects every stored draft result, oldest first.
   *
   * A draft is the result of a draft_content tool call in the transcript.
   *
   * @return array
   *   The result arrays.
   */
  private function draftResults(): array {
    /** @var \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');
    $results = [];
    foreach ($storage->loadTranscript($this) as $message) {
      foreach ($message->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_content' && isset($call['result'])) {
          $results[] = $call['result'];
        }
      }
    }

    return $results;
  }

}
