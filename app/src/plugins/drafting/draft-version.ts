/**
 * Draft version parsing, comparison and display.
 *
 * Mirrors the backend's DraftVersion (src/Utility/DraftVersion.php): a
 * version is "major.minor", and a whole major carries a zero minor on the
 * wire ("3.0", never "3"). This is the one place that turns it back into a
 * draft's name ("Draft 3" or "Draft 3.1") and into a sort key.
 */

/** A version split into its major and minor numbers. */
interface VersionParts {
  major: number;
  minor: number;
}

/**
 * Splits a version into its major and minor.
 *
 * A version with no minor of its own gets a zero one, so "1" gives
 * {major: 1, minor: 0}. Anything we can't parse reads as 0.0, which sorts
 * first and shows as "0" rather than throwing over a version the backend
 * never produces.
 */
function parts(version: string): VersionParts {
  const [majorPart, minorPart] = version.split(".", 2);
  const major = Number.parseInt(majorPart ?? "", 10);
  const minor = Number.parseInt(minorPart ?? "0", 10);
  return {
    major: Number.isNaN(major) ? 0 : major,
    minor: Number.isNaN(minor) ? 0 : minor,
  };
}

/**
 * Compares two versions by their major and minor as numbers.
 *
 * Compared as plain strings, "10" would sort before "9".
 */
export function compareDraftVersions(a: string, b: string): number {
  const pa = parts(a);
  const pb = parts(b);
  return pa.major - pb.major || pa.minor - pb.minor;
}

/**
 * Formats a version the way a draft's name shows it.
 *
 * A whole major shows as "3" rather than "3.0", a revision as "3.1".
 */
export function displayDraftVersion(version: string): string {
  const { major, minor } = parts(version);
  return minor === 0 ? String(major) : `${major}.${minor}`;
}

/** The draft's full name, e.g. "Draft 3" or "Draft 3.1". */
export function draftName(version: string): string {
  return `Draft ${displayDraftVersion(version)}`;
}
