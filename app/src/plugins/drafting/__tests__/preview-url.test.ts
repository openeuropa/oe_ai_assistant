import { describe, expect, it } from "vitest";
import { buildPreviewUrl } from "../preview-url";

// The live preview iframe URL comes from a host-configured template.
describe("buildPreviewUrl", () => {
  it("substitutes the session and version placeholders", () => {
    expect(
      buildPreviewUrl(
        "https://example.com/?session={sessionId}&version={versionId}",
        "dev-session",
        8,
      ),
    ).toBe("https://example.com/?session=dev-session&version=8");
  });

  it("URL-encodes the substituted values", () => {
    expect(
      buildPreviewUrl(
        "https://example.com/?session={sessionId}&version={versionId}",
        "a session/id&x",
        3,
      ),
    ).toBe("https://example.com/?session=a%20session%2Fid%26x&version=3");
  });

  it("leaves templates without placeholders untouched", () => {
    expect(buildPreviewUrl("https://example.com/preview", "s1", 1)).toBe(
      "https://example.com/preview",
    );
  });
});
