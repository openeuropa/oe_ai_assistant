<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_test\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A tool that always throws, so a test can drive toolErrorHandler().
 */
#[NeuronTool(
  id: 'oe_ai_assistant_test_failing',
  description: 'Always fails. Only a test calls it.',
  label: new TranslatableMarkup('Failing tool'),
)]
final class FailingNeuronTool extends NeuronToolPluginBase {

  /**
   * The message a test looks for, shaped like one a driver would throw.
   */
  public const string MESSAGE = 'SQLSTATE[HY000] [2002] Connection refused at db-77:3306';

  /**
   * {@inheritdoc}
   *
   * @throws \RuntimeException
   *   Always.
   */
  public function __invoke(): string {
    throw new \RuntimeException(self::MESSAGE);
  }

}
