<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Utility;

/**
 * Compares and composes a draft's major.minor version.
 *
 * Every stored version carries both parts, so a whole major reads as "3.0"
 * and never as "3". Use display() to print it the way the editor sees it,
 * as "3" or "3.1".
 */
final class DraftVersion {

  /**
   * Sorts a list of versions, lowest first.
   *
   * @param string[] $versions
   *   The versions.
   *
   * @return string[]
   *   The same versions, sorted.
   */
  public static function sort(array $versions): array {
    usort($versions, self::compare(...));

    return $versions;
  }

  /**
   * Compares two versions by their major and minor as numbers.
   *
   * Compared as plain strings, "10" would sort before "9".
   *
   * @param string $a
   *   The first version.
   * @param string $b
   *   The second version.
   *
   * @return int
   *   Negative when $a sorts before $b, positive when after, zero when equal.
   */
  public static function compare(string $a, string $b): int {
    return self::parts($a) <=> self::parts($b);
  }

  /**
   * The next major version, with its minor reset to zero.
   *
   * @param string $version
   *   The version a new major follows.
   *
   * @return string
   *   The next major, as "N.0".
   */
  public static function incrementMajor(string $version): string {
    [$major] = self::parts($version);

    return ($major + 1) . '.0';
  }

  /**
   * The next minor version under the same major.
   *
   * @param string $version
   *   The version a revision follows.
   *
   * @return string
   *   The next minor, under the same major as $version.
   */
  public static function incrementMinor(string $version): string {
    [$major, $minor] = self::parts($version);

    return $major . '.' . ($minor + 1);
  }

  /**
   * The major number of a version.
   *
   * @param string $version
   *   The version.
   *
   * @return int
   *   The major.
   */
  public static function major(string $version): int {
    return self::parts($version)[0];
  }

  /**
   * Adds the minor to a version that has none, so "1" becomes "1.0".
   *
   * @param string $version
   *   The version, with or without a minor.
   *
   * @return string
   *   The version as "major.minor".
   */
  public static function normalize(string $version): string {
    [$major, $minor] = self::parts($version);

    return $major . '.' . $minor;
  }

  /**
   * Formats a version the way a draft's name shows it.
   *
   * A whole major shows as "3" rather than "3.0", a revision as "3.1".
   *
   * @param string $version
   *   The version.
   *
   * @return string
   *   The version without a zero minor.
   */
  public static function display(string $version): string {
    [$major, $minor] = self::parts($version);

    return $minor === 0 ? (string) $major : $major . '.' . $minor;
  }

  /**
   * Splits a version into its major and minor.
   *
   * A version with no minor of its own gets a zero one, so "1" gives
   * [1, 0].
   *
   * @param string $version
   *   The version.
   *
   * @return int[]
   *   The major and the minor, in that order.
   */
  private static function parts(string $version): array {
    [$major, $minor] = array_pad(explode('.', $version, 2), 2, '0');

    return [(int) $major, (int) $minor];
  }

}
