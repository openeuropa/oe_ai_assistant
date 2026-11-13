<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the activity record of an editorial session.
 */
final class SessionActivityController extends ControllerBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns the page title for the activity route.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The session whose activity this page shows.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function title(AiEditorialSessionInterface $ai_editorial_session): TranslatableMarkup {
    return $this->t('Activity: @label', ['@label' => $ai_editorial_session->label()]);
  }

  /**
   * Builds the activity page of a session, oldest entry first.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The session whose activity this page shows.
   *
   * @return array
   *   The render array: one table of every entry the session recorded.
   */
  public function view(AiEditorialSessionInterface $ai_editorial_session): array {
    // getLatestActivity() always takes a limit, and this page shows the
    // whole record. So we count the entries and ask for that many.
    $entries = $ai_editorial_session->getLatestActivity($ai_editorial_session->countActivity());

    $rows = [];
    foreach ($entries as $entry) {
      $rows[] = [
        $entry->id(),
        $this->dateFormatter->format($entry->getCreatedTime(), 'short'),
        $entry->getType()->value,
        $this->actor($entry),
        $entry->getMessage(),
      ];
    }

    $build['activity'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Date'),
        $this->t('Type'),
        $this->t('User'),
        $this->t('What happened'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('This session has recorded no activity yet.'),
    ];

    // Invalidate when the session changes or recordActivity() adds an entry.
    $definition = $this->entityTypeManager()->getDefinition('ai_editorial_session_activity');
    CacheableMetadata::createFromObject($ai_editorial_session)
      ->addCacheContexts($definition->getListCacheContexts())
      ->addCacheTags($definition->getListCacheTags())
      ->applyTo($build);

    return $build;
  }

  /**
   * Names the user an entry records.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface $entry
   *   The entry to read.
   *
   * @return string
   *   The display name, or a dash when the user no longer exists.
   */
  private function actor(AiEditorialSessionActivityInterface $entry): string {
    return (string) ($entry->getOwner()?->getDisplayName() ?? '-');
  }

}
