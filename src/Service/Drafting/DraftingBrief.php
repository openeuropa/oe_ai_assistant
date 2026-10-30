<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;

/**
 * Reads what a session drafts with, once per request.
 *
 * What the editor set up is read on the first call, so what a draft records
 * survives a later rename, and every reader of one session is told the same
 * thing.
 */
class DraftingBrief implements DraftingBriefInterface {

  /**
   * What each session read so far drafts with, keyed by session id.
   */
  private array $briefs = [];

  public function __construct(
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    private readonly AiEditorialContextInterface $tones,
    private readonly ContextDocumentRepository $documents,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tone(AiEditorialSessionInterface $session): ?array {
    return $this->read($session)['tone'];
  }

  /**
   * {@inheritdoc}
   */
  public function template(AiEditorialSessionInterface $session): ?array {
    return $this->read($session)['template'];
  }

  /**
   * {@inheritdoc}
   */
  public function documents(AiEditorialSessionInterface $session): array {
    return $this->read($session)['documents'];
  }

  /**
   * {@inheritdoc}
   */
  public function groups(AiEditorialSessionInterface $session): array {
    return $this->read($session)['groups'];
  }

  /**
   * Reads the session once, and answers the same pieces after that.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   *
   * @return array
   *   The tone, the template, the documents and the groups.
   *
   * @throws \InvalidArgumentException
   *   When the stored template or tone is not one the bundle can draft with.
   */
  private function read(AiEditorialSessionInterface $session): array {
    $id = (string) $session->id();
    if (isset($this->briefs[$id])) {
      return $this->briefs[$id];
    }

    $bundle = $session->getContentType();
    $template = $this->schemaProvider->resolveTemplate(
      self::ENTITY_TYPE_ID,
      $bundle,
      (string) $session->get('template')->target_id,
    );

    $toneId = (string) $session->get('tone')->target_id;

    return $this->briefs[$id] = [
      'tone' => $toneId === '' ? NULL : $this->tones->getTone($toneId),
      'template' => $template === NULL ? NULL : ['id' => $template->id(), 'label' => $template->label()],
      'documents' => $this->documents->describe($session),
      'groups' => $this->schemaProvider->groups(self::ENTITY_TYPE_ID, $bundle, $template?->id()),
    ];
  }

}
