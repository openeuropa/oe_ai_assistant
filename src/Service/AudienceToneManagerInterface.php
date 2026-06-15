<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Provides API-ready audience and tone options and validates selections.
 */
interface AudienceToneManagerInterface {

  /**
   * Returns selectable options of the requested type.
   *
   * @param string $optionType
   *   The option type: "audience" or "tone".
   *
   * @return array<int, array{id: string, label: string, description: string}>
   *   The API-ready options.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the option type is not supported.
   */
  public function getOptions(string $optionType): array;

  /**
   * Returns the selected option values after validating their vocabularies.
   *
   * @param string $optionType
   *   The option type: "audience" or "tone".
   * @param string $id
   *   The selected term ID.
   *
   * @return array{id: string, label: string, description: string}
   *   The validated selected option.
   *
   * @throws \InvalidArgumentException
   *   Thrown when an ID is not valid for the expected vocabulary.
   */
  public function validateSelection(string $optionType, string $id): array;

}
