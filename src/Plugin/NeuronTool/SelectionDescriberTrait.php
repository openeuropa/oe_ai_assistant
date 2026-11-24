<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

/**
 * Describes a session's tone or template selection for the options tools.
 */
trait SelectionDescriberTrait {

  /**
   * Describes what the session holds as one of the options.
   *
   * A session stores only the ID and the label of what it holds, so we look
   * the description up in the options rather than pass the session's own
   * array on. A tone, for instance, is stored with its 'prompt', the AI
   * guidelines, which have no place in this answer. An option the site has
   * since disabled is off the list, so the fallback reports the ID and the
   * label with no description.
   *
   * @param array|null $selection
   *   What the session holds, NULL when it holds nothing.
   * @param array $options
   *   The options to read the description from.
   *
   * @return array|null
   *   The selection with its 'id', 'label' and 'description', or NULL when
   *   the session holds nothing.
   */
  private function describeSelection(?array $selection, array $options): ?array {
    if ($selection === NULL) {
      return NULL;
    }

    $id = (string) ($selection['id'] ?? '');
    foreach ($options as $option) {
      if ($option['id'] === $id) {
        return $option;
      }
    }

    return [
      'id' => $id,
      'label' => $selection['label'] ?? '',
      'description' => '',
    ];
  }

}
