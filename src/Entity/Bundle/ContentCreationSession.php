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
   * The drafts of a session go with its conversation, because a draft is what
   * one run of the conversation wrote.
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
   * The number follows the highest version stored rather than the count, so a
   * session that holds drafts from both paths still numbers the next one
   * after the last the editor saw.
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
   * Collects every stored draft of this session, oldest first.
   *
   * The drafting agent writes a draft to the drafts thread of the session.
   * The chat endpoint writes one as the result of a draft_content tool call
   * in the stored transcript, so this reads both until OEL-5072 retires the
   * second.
   *
   * @todo Drop the transcript branch when the OEL-5066 epic is complete.
   *
   * @return array
   *   One entry per draft, each {version, context, fields}.
   */
  private function draftResults(): array {
    $results = [];
    foreach (\Drupal::service(MessageStoreInterface::class)->loadAll($this->draftsThread()) as $message) {
      $draft = json_decode((string) $message->getContent(), TRUE);
      if (is_array($draft) && isset($draft['version'])) {
        $results[] = $draft;
      }
    }

    /** @var \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');
    foreach ($storage->loadTranscript($this) as $message) {
      foreach ($message->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_content' && isset($call['result'])) {
          $results[] = $call['result'];
        }
      }
    }

    usort($results, static fn (array $a, array $b): int => (int) $a['version'] <=> (int) $b['version']);

    return $results;
  }

}
