<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;

/**
 * Attaches the fixture documents an evaluator judges answers against.
 *
 * The three fixtures are a page each on unrelated subjects, so a judge can
 * tell from one sentence whether the assistant read the document it was
 * asked about. Each page is attached and put through the extraction
 * pipeline, so the summary the assistant reports is one a model wrote from
 * the page rather than one we supplied.
 */
trait EvaluationDocumentsTrait {

  /**
   * Where the fixture pages are written.
   *
   * Their own directory, away from the dated one real uploads go to, so a
   * run that dies before its cleanup leaves nothing among real documents.
   */
  private const string FILE_DIRECTORY = 'private://ai-context-documents/evaluation';

  /**
   * The media created by this run, by the title each was given.
   *
   * @var array
   */
  private array $fixtureDocuments = [];

  /**
   * The fixture pages, by the title the assistant reports them under.
   *
   * @return array
   *   The file name of each fixture, keyed by its title.
   */
  protected function fixtureFiles(): array {
    return [
      'Fishing quotas' => 'fishing-quotas.txt',
      'Language regime' => 'language-regime.txt',
      'Rail signalling' => 'rail-signalling.txt',
    ];
  }

  /**
   * Attaches every fixture document to the session and extracts it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to attach them to.
   *
   * @return array
   *   The id of each attached document, keyed by its title.
   */
  protected function attachFixtureDocuments(AiEditorialSessionInterface $session): array {
    $ids = [];
    foreach ($this->fixtureFiles() as $title => $file) {
      $ids[$title] = $this->attachFixtureDocument($session, $title, $file);
    }

    return $ids;
  }

  /**
   * Attaches one fixture document to the session and extracts it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to attach it to.
   * @param string $title
   *   The title the assistant reports the document under.
   * @param string $file
   *   The name of the fixture page under fixtures/.
   *
   * @return string
   *   The id of the attached document.
   */
  protected function attachFixtureDocument(AiEditorialSessionInterface $session, string $title, string $file): string {
    $text = file_get_contents(__DIR__ . '/fixtures/' . $file);
    if ($text === FALSE) {
      throw new \RuntimeException(sprintf('The fixture page "%s" is missing.', $file));
    }

    $directory = self::FILE_DIRECTORY;
    // prepareDirectory() takes the directory by reference, so it needs a
    // variable and refuses the constant itself.
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    // The file keeps the title's own name on every run. Writing it through
    // the repository would rename a second run's copy to "Title_0.txt",
    // and the assistant reports that name back as the document's title.
    $managed = \Drupal::service('file.repository')->writeData(
      $text,
      $directory . '/' . $title . '.txt',
      FileExists::Replace,
    );

    $media = \Drupal::entityTypeManager()->getStorage('media')->create([
      'bundle' => 'ai_context_document',
      // The listing reports the media label as the document title. Without
      // a label of our own the media type's field map takes it from the
      // file name, and the assistant answers "Fishing quotas.txt".
      'name' => $title,
      'status' => 0,
      'uid' => $session->getOwnerId(),
      'oe_ai_context_document' => ['target_id' => $managed->id()],
      ContextDocumentRepository::SESSION_FIELD => ['target_id' => $session->id()],
    ]);
    $media->save();
    $this->fixtureDocuments[$title] = $media;

    // The loader reads the page and the summary agent writes a summary of
    // it. That is what makes a summary worth judging against the full text.
    \Drupal::service(ContextDocumentRepository::class)->extract($session, (string) $media->id());

    return (string) $media->id();
  }

  /**
   * Deletes every fixture document this run created, and its file.
   *
   * The evaluation framework calls no tear-down of its own, so a caller
   * does this from the finally block that ends its run.
   */
  protected function deleteFixtureDocuments(): void {
    foreach ($this->fixtureDocuments as $title => $media) {
      $fresh = \Drupal::entityTypeManager()->getStorage('media')->load($media->id());
      if ($fresh instanceof MediaInterface) {
        $file = $fresh->get('oe_ai_context_document')->entity;
        $fresh->delete();
        $file?->delete();
      }
      unset($this->fixtureDocuments[$title]);
    }
  }

}
