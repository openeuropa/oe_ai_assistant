<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Hook\DocumentMediaHooks;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for DocumentMediaHooks::mediaAccess().
 */
#[Group('oe_ai_assistant')]
class DocumentMediaHooksAccessTest extends UnitTestCase {

  /**
   * The hooks object under test.
   */
  private DocumentMediaHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->hooks = new DocumentMediaHooks($this->createMock(DocumentExtractionProcessorInterface::class));
  }

  /**
   * Builds a session mock stubbed with cacheable-dependency defaults.
   */
  private function mockSession(bool $access): AiEditorialSessionInterface {
    $session = $this->createMock(AiEditorialSessionInterface::class);
    $session->method('access')->willReturn($access);
    $session->method('getCacheContexts')->willReturn([]);
    $session->method('getCacheTags')->willReturn([]);
    $session->method('getCacheMaxAge')->willReturn(-1);
    return $session;
  }

  /**
   * Builds a media mock with the given bundle and session reference field.
   */
  private function mockMedia(string $bundle, ?AiEditorialSessionInterface $session): MediaInterface {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn($bundle);
    $media->method('getCacheContexts')->willReturn([]);
    $media->method('getCacheTags')->willReturn([]);
    $media->method('getCacheMaxAge')->willReturn(-1);

    if ($bundle === 'ai_context_document') {
      $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
      $field->method('referencedEntities')->willReturn($session !== NULL ? [$session] : []);
      $media->method('get')->with('oe_ai_session')->willReturn($field);
    }

    return $media;
  }

  /**
   * Tests a non-ai_context_document bundle is neutral.
   */
  public function testNonContextDocumentBundleIsNeutral(): void {
    $media = $this->mockMedia('image', NULL);
    $account = $this->createMock(AccountInterface::class);

    $result = $this->hooks->mediaAccess($media, 'view', $account);

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Tests an orphaned document (no session) is forbidden.
   */
  public function testOrphanedDocumentIsForbidden(): void {
    $media = $this->mockMedia('ai_context_document', NULL);
    $account = $this->createMock(AccountInterface::class);

    $result = $this->hooks->mediaAccess($media, 'view', $account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests a session-denied document is forbidden.
   */
  public function testSessionDeniedIsForbidden(): void {
    $session = $this->mockSession(FALSE);
    $media = $this->mockMedia('ai_context_document', $session);
    $account = $this->createMock(AccountInterface::class);

    $result = $this->hooks->mediaAccess($media, 'view', $account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests a session-allowed document stays neutral, letting core decide.
   */
  public function testSessionAllowedIsNeutral(): void {
    $session = $this->mockSession(TRUE);
    $media = $this->mockMedia('ai_context_document', $session);
    $account = $this->createMock(AccountInterface::class);

    $result = $this->hooks->mediaAccess($media, 'view', $account);

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Tests non-view operations are checked against the session's update op.
   */
  public function testNonViewOperationUsesSessionUpdate(): void {
    $session = $this->createMock(AiEditorialSessionInterface::class);
    $session->expects($this->once())->method('access')->with('update', $this->anything())->willReturn(TRUE);
    $session->method('getCacheContexts')->willReturn([]);
    $session->method('getCacheTags')->willReturn([]);
    $session->method('getCacheMaxAge')->willReturn(-1);
    $media = $this->mockMedia('ai_context_document', $session);
    $account = $this->createMock(AccountInterface::class);

    $this->hooks->mediaAccess($media, 'delete', $account);
  }

}
