/**
 * Formats a draft creation time for the preview header.
 *
 * Produces "Friday, 22 May 2026 at 14:30" in the viewer's local time
 * zone. Built from individual parts rather than a locale date style so
 * the punctuation is stable across ICU versions.
 */
export function formatDraftDate(date: Date): string {
  const weekday = date.toLocaleDateString("en-GB", { weekday: "long" });
  const day = date.getDate();
  const month = date.toLocaleDateString("en-GB", { month: "long" });
  const year = date.getFullYear();
  const time = date.toLocaleTimeString("en-GB", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
  return `${weekday}, ${day} ${month} ${year} at ${time}`;
}
