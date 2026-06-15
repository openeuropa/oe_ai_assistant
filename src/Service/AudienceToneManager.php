<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Builds API-safe audience and tone option data.
 */
class AudienceToneManager implements AudienceToneManagerInterface {

  public function __construct(
    private readonly AiEditorialContextInterface $editorialContext,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getOptions(string $optionType): array {
    return match ($optionType) {
      'audience' => $this->normalizeOptions($this->editorialContext->getAvailableAudiences()),
      'tone' => $this->normalizeOptions($this->editorialContext->getAvailableTones()),
      default => throw new \InvalidArgumentException(sprintf(
        'Unsupported option type "%s".',
        $optionType,
      )),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function validateSelection(string $optionType, string $id): array {
    $option = $this->findOption($this->getOptions($optionType), $id);
    if ($option === NULL) {
      throw new \InvalidArgumentException(sprintf(
        '%s ID "%s" is not selectable.',
        ucfirst($optionType),
        $id,
      ));
    }

    return $option;
  }

  /**
   * Converts prompt-ready helper output into API-safe option objects.
   *
   * @param array<int, array{id: string, name: string, description: string, oe_ai_prompt: string}> $options
   *   Prompt-ready options from the editorial context helper.
   *
   * @return array<int, array{id: string, label: string, description: string}>
   *   API-safe options.
   */
  private function normalizeOptions(array $options): array {
    return array_map(
      static fn(array $option): array => [
        'id' => $option['id'],
        'label' => $option['name'],
        'description' => $option['description'],
      ],
      $options,
    );
  }

  /**
   * Finds an option by ID.
   *
   * @param array<int, array{id: string, label: string, description: string}> $options
   *   The option list to search.
   * @param string $id
   *   The option ID.
   *
   * @return array{id: string, label: string, description: string}|null
   *   The matching option, if found.
   */
  private function findOption(array $options, string $id): ?array {
    foreach ($options as $option) {
      if ($option['id'] === $id) {
        return $option;
      }
    }

    return NULL;
  }

}
