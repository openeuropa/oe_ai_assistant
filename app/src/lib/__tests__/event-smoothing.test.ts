/**
 * Tests for the AG-UI event smoothing middleware.
 *
 * Verifies that the smoothing queue handles both STATE_SNAPSHOT
 * and STATE_DELTA events correctly, releasing them at controlled
 * intervals regardless of event type.
 */

import { describe, expect, it, vi } from "vitest";
import { Observable } from "rxjs";
import {
  createSmoothingMiddleware,
  type EventSmoothingConfig,
} from "../event-smoothing";

/** Helper: collects all events from a middleware-wrapped source. */
function collectEvents<T>(observable: Observable<T>): Promise<T[]> {
  return new Promise((resolve, reject) => {
    const events: T[] = [];
    observable.subscribe({
      next: (e) => events.push(e),
      error: reject,
      complete: () => resolve(events),
    });
  });
}

/** Helper: creates a mock "next" object for the middleware. */
function mockNext(events: Record<string, unknown>[]) {
  return {
    run: () =>
      new Observable<Record<string, unknown>>((subscriber) => {
        for (const e of events) subscriber.next(e);
        subscriber.complete();
      }),
  };
}

describe("createSmoothingMiddleware", () => {
  it("passes STATE_SNAPSHOT events through", async () => {
    const config: EventSmoothingConfig = {
      enabled: true,
      intervalMs: 1,
    };
    const middleware = createSmoothingMiddleware(config);
    const events = [
      { type: "STATE_SNAPSHOT", snapshot: { draftedFields: {} } },
      {
        type: "STATE_SNAPSHOT",
        snapshot: { draftedFields: { title: "Hello" } },
      },
    ];

    const result = await collectEvents(
      middleware({}, mockNext(events)),
    );

    expect(result).toHaveLength(2);
    expect(result[0]).toEqual(events[0]);
    expect(result[1]).toEqual(events[1]);
  });

  it("passes STATE_DELTA events through", async () => {
    const config: EventSmoothingConfig = {
      enabled: true,
      intervalMs: 1,
    };
    const middleware = createSmoothingMiddleware(config);
    const events = [
      {
        type: "STATE_DELTA",
        delta: [
          {
            op: "replace",
            path: "/draftedFields/body/value",
            value: "Hello",
          },
        ],
      },
      {
        type: "STATE_DELTA",
        delta: [
          {
            op: "replace",
            path: "/draftedFields/body/value",
            value: "Hello world",
          },
        ],
      },
    ];

    const result = await collectEvents(
      middleware({}, mockNext(events)),
    );

    expect(result).toHaveLength(2);
    expect(result[0]).toEqual(events[0]);
    expect(result[1]).toEqual(events[1]);
  });

  it("handles mixed snapshot and delta events", async () => {
    const config: EventSmoothingConfig = {
      enabled: true,
      intervalMs: 1,
    };
    const middleware = createSmoothingMiddleware(config);
    const events = [
      { type: "STATE_SNAPSHOT", snapshot: { draftedFields: {} } },
      {
        type: "STATE_DELTA",
        delta: [
          {
            op: "replace",
            path: "/draftedFields/body",
            value: "Word",
          },
        ],
      },
      {
        type: "STATE_SNAPSHOT",
        snapshot: { draftedFields: { body: "Final" } },
      },
    ];

    const result = await collectEvents(
      middleware({}, mockNext(events)),
    );

    expect(result).toHaveLength(3);
    expect(result.map((e) => e.type)).toEqual([
      "STATE_SNAPSHOT",
      "STATE_DELTA",
      "STATE_SNAPSHOT",
    ]);
  });

  it("passes events through unchanged when disabled", async () => {
    const config: EventSmoothingConfig = {
      enabled: false,
      intervalMs: 1,
    };
    const middleware = createSmoothingMiddleware(config);
    const events = [
      {
        type: "STATE_DELTA",
        delta: [
          {
            op: "replace",
            path: "/draftedFields/body",
            value: "Hi",
          },
        ],
      },
    ];

    const result = await collectEvents(
      middleware({}, mockNext(events)),
    );

    expect(result).toHaveLength(1);
    expect(result[0]).toEqual(events[0]);
  });
});
