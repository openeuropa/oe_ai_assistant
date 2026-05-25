<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiContextScope;

use Drupal\ai_context\Attribute\AiContextScope;
use Drupal\ai_context\Entity\AiContextItem;
use Drupal\ai_context\Plugin\AiContextScope\AiContextScopeBase;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[AiContextScope(
  id: 'oe_assistant_plugin',
  label: new TranslatableMarkup('OE Assistant plugin'),
  description: new TranslatableMarkup('The OE assistant plugin using in an agent.'),
  weight: 20,
)]
class AiContextScopeAssistantPlugin extends AiContextScopeBase {

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected CurrentRouteMatch $currentRouteMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->currentRouteMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getValues(): array {
    return [
      'agent_test' => $this->t('Agent test'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function supportsSubscriptions(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentValue(): ?string {
    if ($this->currentRouteMatch->getRouteName() !== 'oe_ai_assistant.plugin.dispatch') {
      return NULL;
    }

    $plugin_id = $this->currentRouteMatch->getParameter('plugin_id');
    return is_string($plugin_id) ? $plugin_id : NULL;
  }

}
