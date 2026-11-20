<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;

/**
 * Creates extracted context documents for the research tests.
 */
trait ResearchDocumentTrait {

  /**
   * Creates a context document of a session with a given extract.
   *
   * @return int
   *   The document id.
   */
  private function createExtractedDocument(AiEditorialSessionInterface $session, string $title, ?string $extract, string $summary = ''): int {
    $name = 'doc-' . (++$this->testEntityCounter) . '.txt';
    file_put_contents('public://' . $name, 'payload');
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => $title,
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $file->id()],
      'oe_ai_session' => ['target_id' => $session->id()],
    ]);
    $media->save();
    // The insert starts the extraction pipeline from scratch, so the extract
    // is stored by an update, as the pipeline itself does.
    if ($extract !== NULL) {
      $media->set(DocumentExtractionProcessorInterface::EXTRACT_FIELD, $extract)
        ->set(DocumentExtractionProcessorInterface::SUMMARY_FIELD, $summary)
        ->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_DONE)
        ->save();
    }

    return (int) $media->id();
  }

}
