<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\oe_ai_assistant\Service\AiInteractionLogMapper;
use Drupal\oe_ai_assistant\Service\AiInteractionLogPersister;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures AI observability response events in custom storage.
 */
class AiInteractionLogSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new subscriber.
   */
  public function __construct(
    protected readonly AiInteractionLogMapper $mapper,
    protected readonly AiInteractionLogPersister $persister,
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => 'onPostGenerateResponse',
      PostStreamingResponseEvent::EVENT_NAME => 'onPostStreamingResponse',
    ];
  }

  /**
   * Persists the response observability payload.
   */
  public function onPostGenerateResponse(PostGenerateResponseEvent $event): void {
    if ($this->isStreamingResponse($event)) {
      return;
    }

    $this->persistEvent($event);
  }

  /**
   * Persists the completed streaming response observability payload.
   */
  public function onPostStreamingResponse(PostStreamingResponseEvent $event): void {
    $this->persistEvent($event);
  }

  /**
   * Persists the response event without interrupting the provider response.
   */
  protected function persistEvent(PostGenerateResponseEvent|PostStreamingResponseEvent $event): void {
    try {
      $this->persister->persist($this->mapper->mapResponseEvent($event));
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to persist AI interaction log for provider request @request_id: @message', [
        '@request_id' => $event->getRequestThreadId(),
        '@message' => $exception->getMessage(),
        'exception' => $exception,
      ]);
    }
  }

  /**
   * Determines whether a post-generate event will have a later stream event.
   */
  protected function isStreamingResponse(PostGenerateResponseEvent $event): bool {
    $output = $event->getOutput();

    return $output instanceof ChatOutput
      && $output->getNormalized() instanceof StreamedChatMessageIteratorInterface;
  }

}
