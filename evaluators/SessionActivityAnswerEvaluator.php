<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use NeuronAI\Evaluation\Assertions\Judges\CorrectnessJudge;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Checks that the assistant answers what happened in the session it serves.
 *
 * The editorial agent answers such a question through
 * context_get_session_activity, which reads the activity record. Each
 * checkpoint asks one question right after the change it is about, so a
 * failure names the change the record lost.
 */
final class SessionActivityAnswerEvaluator extends EditorialSessionEvaluatorBase {

  /**
   * {@inheritdoc}
   */
  public function getDataset(): DatasetInterface {
    return new ArrayDataset([
      [
        'input' => [
          'tone' => 'Formal',
          'changed_tone' => 'Technical',
          'document' => [
            'title' => 'Press Release',
            'text' => 'The product launches on March 3rd in Brussels.',
          ],
        ],
        // One fact per checkpoint. A judge scores a reply that answers half
        // of a two-part question as half wrong, so the count of the tone
        // changes and the tone before them are asked apart.
        'expected_output' => [
          'created' => 'The session uses the Formal tone.',
          'tone_change_count' => 'The tone of the session changed once.',
          'tone_before_change' => 'Before the change the tone was Formal.',
          'document_attached' => 'One document is attached, named "Press Release.txt".',
          'document_removed' => 'The document "Press Release.txt" was attached, and then removed.',
        ],
      ],
    ]);
  }

  /**
   * Asks one question after each change the editor makes.
   *
   * @param array $datasetItem
   *   The item to run, carrying the tones and the document under 'input'.
   *
   * @return array
   *   The turn of each checkpoint, keyed as 'expected_output' is.
   */
  public function run(array $datasetItem): mixed {
    $input = $datasetItem['input'];
    [$session, $owner] = $this->createSession(tone: $input['tone']);
    $sessionId = (int) $session->id();
    $switcher = \Drupal::service('account_switcher');

    // Attaching a document saves a media that references the uploaded file,
    // and Drupal checks that the current user may reference it. On the
    // command line we start out as anonymous, so we run as the owner.
    $switcher->switchTo($owner);

    try {
      $answers = [
        'created' => $this->ask($sessionId, 'What tone is this session using?'),
      ];

      $this->setTone($sessionId, $input['changed_tone']);
      $answers['tone_change_count'] = $this->ask($sessionId, 'How many times has the tone changed in this session?');
      $answers['tone_before_change'] = $this->ask($sessionId, 'What was the tone of this session before the last change?');

      $documentId = $this->attachDocument($sessionId, $input['document']);
      $answers['document_attached'] = $this->ask($sessionId, 'Which documents are attached to this session?');

      \Drupal::service(ContextDocumentRepository::class)->remove($this->session($sessionId), $documentId);
      $answers['document_removed'] = $this->ask($sessionId, 'What happened to the documents of this session?');
    }
    finally {
      $switcher->switchBack();
      $this->deleteSession($sessionId, $owner);
    }

    return $answers;
  }

  /**
   * Scores each answer against what the record held when it was asked.
   */
  public function evaluate(mixed $output, array $datasetItem): void {
    $this->assert(
      new ToolWasCalled('context_get_session_activity'),
      $output['created'],
      'tool_called',
    );

    foreach ($datasetItem['expected_output'] as $checkpoint => $expected) {
      $this->assert(
        new CorrectnessJudge(judge: $this->judge(), expected: $expected, threshold: 0.7),
        $output[$checkpoint]->finalAnswer(),
        $checkpoint,
      );
    }
  }

  /**
   * Picks a tone, the way any writer of the field does.
   *
   * @param int $sessionId
   *   The session to change.
   * @param string $label
   *   The name of the tone term to pick.
   */
  private function setTone(int $sessionId, string $label): void {
    $session = $this->session($sessionId);
    $session->set('tone', $this->tone($label)->id());
    $session->save();
  }

  /**
   * Attaches one document and extracts its text.
   *
   * @param int $sessionId
   *   The session to attach the document to.
   * @param array $document
   *   The document to write to a temporary file, with a title and a text.
   *
   * @return string
   *   The id of the attached document.
   */
  private function attachDocument(int $sessionId, array $document): string {
    $path = tempnam(sys_get_temp_dir(), 'eval_doc_');
    file_put_contents($path, $document['text']);
    // The name is the one an expectation names, so the run deletes the
    // session it attached to and core has the name free again. A leftover
    // file would make core store the next one as "Press Release_0.txt".
    $filename = $document['title'] . '.txt';
    $upload = new InputStreamUploadedFile($filename, $filename, $path, @filesize($path));

    $repository = \Drupal::service(ContextDocumentRepository::class);
    $attached = $repository->add($this->session($sessionId), $upload);
    $repository->extract($this->session($sessionId), $attached['id']);

    return $attached['id'];
  }

}
