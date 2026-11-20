<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Exception\DocumentPagerException;
use Drupal\oe_ai_assistant\Service\Subagent\DocumentPager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pager that serves the extracted text of session documents.
 */
#[Group('oe_ai_assistant')]
class DocumentPagerTest extends AiEditorialSessionKernelTestBase {

  use ResearchDocumentTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * The pager under test.
   */
  private function pager(): DocumentPager {
    return $this->container->get(DocumentPager::class);
  }

  /**
   * Tests page counts, page boundaries and documents without an extract.
   */
  public function testListAndReadPages(): void {
    $session = $this->createSession($this->createUser());
    $text = str_repeat('a', DocumentPager::PAGE_CHARS) . str_repeat('b', 10);
    $id = $this->createExtractedDocument($session, 'Report', $text, 'A report.');
    $exact = $this->createExtractedDocument($session, 'Exact', str_repeat('c', DocumentPager::PAGE_CHARS));
    $this->createExtractedDocument($session, 'Pending', NULL);

    $list = $this->pager()->listDocuments($session);
    $this->assertSame([
      ['document_id' => $id, 'title' => 'Report', 'summary' => 'A report.', 'characters' => 4010, 'pages' => 2],
      ['document_id' => $exact, 'title' => 'Exact', 'summary' => '', 'characters' => 4000, 'pages' => 1],
    ], $list);

    $first = $this->pager()->read($session, $id, 1);
    $this->assertSame(str_repeat('a', 4000), $first['text']);
    $this->assertSame(2, $first['pages']);
    $this->assertSame(1, $first['page']);
    $this->assertSame('Report', $first['title']);
    $this->assertSame(str_repeat('b', 10), $this->pager()->read($session, $id, 2)['text']);
    $this->assertSame(1, $this->pager()->read($session, $exact, 1)['pages']);
  }

  /**
   * Tests that pages are counted in characters, not bytes.
   */
  public function testMultibyteText(): void {
    $session = $this->createSession($this->createUser());
    $id = $this->createExtractedDocument($session, 'Accents', str_repeat('é', 4001));

    $this->assertSame(4001, $this->pager()->listDocuments($session)[0]['characters']);
    $this->assertSame(str_repeat('é', 4000), $this->pager()->read($session, $id, 1)['text']);
    $this->assertSame('é', $this->pager()->read($session, $id, 2)['text']);
  }

  /**
   * Tests the requests the pager refuses.
   */
  public function testReadRefusals(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $other = $this->createSession($owner);
    $id = $this->createExtractedDocument($session, 'Mine', 'text');
    $foreign = $this->createExtractedDocument($other, 'Theirs', 'text');
    $empty = $this->createExtractedDocument($session, 'Empty', NULL);

    foreach ([0, 2] as $page) {
      try {
        $this->pager()->read($session, $id, $page);
        $this->fail('An out-of-range page was served.');
      }
      catch (DocumentPagerException $e) {
        $this->assertStringContainsString('has pages 1 to 1', $e->getMessage());
      }
    }
    foreach ([$foreign, $empty, 9999] as $documentId) {
      try {
        $this->pager()->read($session, $documentId, 1);
        $this->fail('A document outside the session was served.');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertInstanceOf(DocumentPagerException::class, $e);
        $this->assertStringContainsString('not an available document', $e->getMessage());
      }
    }
    $this->assertCount(1, $this->pager()->listDocuments($other));
  }

  /**
   * Tests the ranking and the snippets of a search.
   */
  public function testSearch(): void {
    $session = $this->createSession($this->createUser());
    $filler = str_repeat('x', 3000);
    $one = $this->createExtractedDocument($session, 'One', $filler . ' Budget ' . str_repeat('y', 500));
    $two = $this->createExtractedDocument($session, 'Two', 'The budget and the BUDGET plan, with a deadline.');
    $other = $this->createSession($this->createUser());
    $this->createExtractedDocument($other, 'Foreign', 'budget budget budget budget');

    $results = $this->pager()->search($session, 'Budget  deadline of');
    $this->assertSame([$two, $one], array_column($results, 'document_id'));
    $this->assertSame([1, 1], array_column($results, 'page'));
    $this->assertSame('The budget and the BUDGET plan, with a deadline.', $results[0]['snippet']);

    // The snippet holds 200 characters on either side of the hit, and an
    // ellipsis marks each cut.
    $this->assertStringStartsWith('…' . str_repeat('x', 199) . ' Budget', $results[1]['snippet']);
    $this->assertStringEndsWith('…', $results[1]['snippet']);
    $this->assertSame(402, mb_strlen($results[1]['snippet']));

    $this->assertCount(1, $this->pager()->search($session, 'deadline'));
    $this->assertSame([], $this->pager()->search($session, 'missing'));
    $this->assertCount(1, $this->pager()->search($session, 'budget', 1));
  }

  /**
   * Tests that a query without a usable term is refused.
   */
  public function testEmptyQuery(): void {
    $session = $this->createSession($this->createUser());
    foreach (['', '   ', 'of to'] as $query) {
      try {
        $this->pager()->search($session, $query);
        $this->fail('A query without terms was accepted.');
      }
      catch (DocumentPagerException $e) {
        $this->assertStringContainsString('no search term', $e->getMessage());
      }
    }
  }

}
