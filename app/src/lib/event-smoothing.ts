/**
 * AG-UI event smoothing middleware.
 *
 * SSE events often arrive in bursts due to buffering at various
 * layers (PHP output buffers, nginx, Traefik, CDN). This middleware
 * smooths out bursty delivery by queuing events and releasing them
 * at a controlled pace, creating a steady "typewriter" effect at
 * the event level.
 *
 * When events arrive faster than the drain interval, they queue up
 * and are released one at a time. When events arrive slower than
 * the drain interval, they pass through immediately. This means
 * the artificial delay only kicks in during bursts -- if the
 * backend is already streaming smoothly, no extra latency is added.
 *
 * Configuration:
 * - enabled: toggle smoothing on/off (default: true)
 * - intervalMs: minimum delay between events (default: 30ms)
 *
 * Use a shorter interval (15-20ms) for snappy UX, or a longer one
 * (50-80ms) to mask larger buffering gaps. Set enabled=false when
 * using a Node backend that streams natively without buffering.
 */

import type { Observable } from "rxjs";

/** Options for the event smoothing behaviour. */
export interface EventSmoothingConfig {
  /** Whether to enable event smoothing. Default: true. */
  enabled: boolean;
  /** Minimum milliseconds between delivered events. Default: 30. */
  intervalMs: number;
}

/** Sensible defaults for environments with buffering (e.g. PHP). */
export const defaultSmoothingConfig: EventSmoothingConfig = {
  enabled: true,
  intervalMs: 30,
};

/** Observer interface matching what RxJS Observable accepts. */
interface SimpleObserver<T> {
  next: (value: T) => void;
  error: (err: unknown) => void;
  complete: () => void;
}

/**
 * Creates a smoothing AG-UI middleware function.
 *
 * Returns a middleware that can be passed to `httpAgent.use()`.
 * When smoothing is disabled, the middleware is a no-op passthrough.
 *
 * Usage:
 *   httpAgent.use(createSmoothingMiddleware(config));
 */
export function createSmoothingMiddleware(
  config: EventSmoothingConfig,
) {
  // biome-ignore lint/suspicious/noExplicitAny: AG-UI middleware types use any for RunAgentInput and BaseEvent
  return (input: any, next: any): Observable<any> => {
    const source = next.run(input);
    if (!config.enabled) return source;
    return smoothObservable(source, config.intervalMs);
  };
}

/**
 * Wraps an Observable to smooth out bursty event delivery.
 *
 * Events are queued as they arrive from the source. A drain loop
 * releases them one at a time, waiting at least `intervalMs`
 * between each emission. If the queue is empty when the drain
 * loop fires, it pauses until the next event arrives.
 *
 * Uses the source Observable's constructor so the returned
 * Observable is the same RxJS version as the source (avoids
 * duplicate-rxjs type conflicts).
 */
function smoothObservable<T>(
  source: Observable<T>,
  intervalMs: number,
): Observable<T> {
  // Use the source's own Observable constructor to avoid type
  // conflicts between different rxjs versions in node_modules.
  const ObservableCtor = source.constructor as {
    new (subscribe: (observer: SimpleObserver<T>) => () => void): Observable<T>;
  };

  return new ObservableCtor((observer: SimpleObserver<T>) => {
    const queue: T[] = [];
    let draining = false;
    let sourceComplete = false;
    let disposed = false;

    /**
     * Drains the queue one event at a time, waiting intervalMs
     * between each. Stops when the queue is empty (will restart
     * when new events arrive) or when disposed.
     */
    function drain() {
      if (disposed || draining) return;
      draining = true;

      function step() {
        if (disposed) {
          draining = false;
          return;
        }

        if (queue.length > 0) {
          observer.next(queue.shift() as T);
          // Schedule the next event after the interval.
          setTimeout(step, intervalMs);
        } else {
          // Queue empty -- stop draining. Will restart when
          // the next event arrives via the subscription.
          draining = false;
          // If the source has completed and the queue is now
          // empty, signal completion downstream.
          if (sourceComplete) {
            observer.complete();
          }
        }
      }

      step();
    }

    const subscription = source.subscribe({
      next(value: T) {
        if (disposed) return;
        queue.push(value);
        // Kick off the drain loop if it is not already running.
        if (!draining) {
          drain();
        }
      },
      error(err: unknown) {
        if (disposed) return;
        // Errors are delivered immediately -- no point queuing.
        disposed = true;
        observer.error(err);
      },
      complete() {
        sourceComplete = true;
        // If the queue is already empty, complete immediately.
        // Otherwise the drain loop will complete after flushing.
        if (!draining && queue.length === 0) {
          observer.complete();
        }
      },
    });

    // Teardown: unsubscribe from source and discard queued events.
    return () => {
      disposed = true;
      queue.length = 0;
      subscription.unsubscribe();
    };
  });
}
