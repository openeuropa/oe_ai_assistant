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

/**
 * Formats a draft creation time for the draft card.
 *
 * Produces "Mon, 9 Nov 2026, at 9:40" in the viewer's local time zone. The
 * card gives the time the end of a line it shares with the draft name, so
 * this form carries the short weekday and month and drops the leading zero
 * of the hour. Built from individual parts rather than a locale date style
 * so the punctuation is stable across ICU versions.
 */
export function formatDraftDateShort(date: Date): string {
  const weekday = date.toLocaleDateString("en-GB", { weekday: "short" });
  const day = date.getDate();
  const month = date.toLocaleDateString("en-GB", { month: "short" });
  const year = date.getFullYear();
  const minute = String(date.getMinutes()).padStart(2, "0");
  return `${weekday}, ${day} ${month} ${year}, at ${date.getHours()}:${minute}`;
}
