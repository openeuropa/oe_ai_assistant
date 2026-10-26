<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Hook\DocumentMediaHooks;
use Drupal\oe_ai_assistant\Hook\SessionAccessQueryAlterInterface;
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
    $this->hooks = new DocumentMediaHooks(
      $this->createMock(DocumentExtractionProcessorInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(SessionAccessQueryAlterInterface::class),
    );

    // mediaCreateAccess() calls cachePerPermissions(), whose assert()
    // validates the context token through this service.
    $cacheContextsManager = $this->getMockBuilder('Drupal\Core\Cache\Context\CacheContextsManager')
      ->disableOriginalConstructor()
      ->getMock();
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a session mock stubbed with cacheable-dependency defaults.
   */
  private function mockSession(bool $access): AiEditorialSessionInterface {
    $session = $this->createMock(AiEditorialSessionInterface::class);
    $session->method('access')->willReturn($access ? AccessResult::allowed() : AccessResult::forbidden());
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
    $session->expects($this->once())->method('access')->with('update', $this->anything(), TRUE)->willReturn(AccessResult::allowed());
    $session->method('getCacheContexts')->willReturn([]);
    $session->method('getCacheTags')->willReturn([]);
    $session->method('getCacheMaxAge')->willReturn(-1);
    $media = $this->mockMedia('ai_context_document', $session);
    $account = $this->createMock(AccountInterface::class);

    $this->hooks->mediaAccess($media, 'delete', $account);
  }

  /**
   * Tests a non-ai_context_document bundle is neutral on create.
   */
  public function testCreateAccessNonContextDocumentBundleIsNeutral(): void {
    $account = $this->createMock(AccountInterface::class);

    $result = $this->hooks->mediaCreateAccess($account, [], 'image');

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Tests ai_context_document creation is forbidden through this gate.
   *
   * The drafting plugin's own upload flow never calls createAccess(), so
   * this cannot block it; it only closes generic create routes (/media/add
   * and similar) which have no session to validate a document against.
   */
  public function testCreateAccessContextDocumentBundleIsForbidden(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = $this->hooks->mediaCreateAccess($account, [], 'ai_context_document');

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Tests session administrators keep a way through the gate.
   */
  public function testCreateAccessContextDocumentBundleIsNeutralForSessionAdmins(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('administer ai editorial sessions')->willReturn(TRUE);

    $result = $this->hooks->mediaCreateAccess($account, [], 'ai_context_document');

    $this->assertTrue($result->isNeutral());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

}
