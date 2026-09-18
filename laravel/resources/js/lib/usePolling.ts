import { useEffect, useRef } from 'react';

/**
 * Polls an async callback on an interval with no overlapping in-flight calls.
 *
 * While `enabled` is true the callback runs immediately and then again
 * `intervalMs` after each call settles (recursion, so a slow request never
 * stacks). Polling stops on unmount or when `enabled` flips to false — pass a
 * terminal/paused condition as `!enabled` to halt it. The latest `callback` is
 * always used without restarting the loop.
 *
 * Shared by the job-detail, queue and live-map screens; import, never fork.
 */
export function usePolling(
    callback: () => Promise<void> | void,
    intervalMs: number,
    enabled = true,
): void {
    const savedCallback = useRef(callback);
    savedCallback.current = callback;

    useEffect(() => {
        if (!enabled) {
            return;
        }
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | null = null;

        const tick = async () => {
            try {
                await savedCallback.current();
            } catch {
                // Swallow: a callback that forgets to catch must not surface as an
                // unhandled rejection, and the loop must survive it and reschedule.
            } finally {
                if (!cancelled) {
                    timer = setTimeout(tick, intervalMs);
                }
            }
        };

        void tick();

        return () => {
            cancelled = true;
            if (timer) {
                clearTimeout(timer);
            }
        };
    }, [intervalMs, enabled]);
}
