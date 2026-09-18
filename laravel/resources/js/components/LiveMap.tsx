import { useEffect, useRef, useState } from 'react';

import { get } from '@/lib/api';
import { usePolling } from '@/lib/usePolling';
import type { DataResponse, JobPosition } from '@/lib/types';

const POLL_MS = 4000;

/** Consecutive failed polls before the map gives up and stops retrying. */
const MAX_ERRORS = 5;

/**
 * Mock live map. Polls the job position endpoint and draws a deterministic
 * pickup -> drop route with the rider's interpolated position. Renders nothing
 * while the job is outside the live-map window (the endpoint returns null), so
 * callers can show a "status and ETA only" message alongside it.
 *
 * Whether to keep polling is driven by the JOB's terminal state (the `terminal`
 * prop the caller derives from the record it already polls), not by a null
 * position: a null position is a transient out-of-window state (pre-pickup, or
 * `failed` before the rider starts the return leg), so the map must resume once
 * a live status such as `returning` puts the position back in view. Polling
 * stops only when the job is truly done or after repeated request failures.
 *
 * Reused by the recipient (token-scoped) and the customer/rider/ops job views
 * (session-authenticated); pass `token` for the unauthenticated recipient path.
 */
export function LiveMap({
    jobId,
    token,
    terminal = false,
}: {
    jobId: number;
    token?: string;
    terminal?: boolean;
}) {
    const [position, setPosition] = useState<JobPosition | null>(null);
    const [stopped, setStopped] = useState(false);
    const errorCount = useRef(0);
    // Bumped whenever the viewed job/token changes; captured per request so a
    // response for a previous job that resolves late is discarded instead of
    // wedging the newly viewed job.
    const requestKey = useRef(0);

    // Reset polling state when the viewed job changes, so a fresh (possibly
    // non-terminal) job resumes polling even after a previous one stopped.
    useEffect(() => {
        requestKey.current += 1;
        setPosition(null);
        setStopped(false);
        errorCount.current = 0;
    }, [jobId, token]);

    const path = token
        ? `/jobs/${jobId}/position?token=${encodeURIComponent(token)}`
        : `/jobs/${jobId}/position`;

    const poll = async () => {
        const key = requestKey.current;
        try {
            const response = await get<DataResponse<JobPosition | null>>(path);
            if (key !== requestKey.current) {
                return; // job/token changed mid-flight; this response is stale
            }
            errorCount.current = 0;
            // Apply the position as-is: non-null renders the map, null hides it
            // until the window reopens. Stopping is the job's terminal state's job.
            setPosition(response.data);
        } catch {
            if (key !== requestKey.current) {
                return;
            }
            errorCount.current += 1;
            if (errorCount.current >= MAX_ERRORS) {
                setPosition(null);
                setStopped(true); // give up after repeated failures instead of hammering
            }
        }
    };

    usePolling(poll, POLL_MS, !terminal && !stopped);

    if (!position) {
        return null;
    }

    const pct = (value: number) => value * 100;

    return (
        <div className="overflow-hidden rounded-lg border bg-muted/40">
            <svg
                viewBox="0 0 100 100"
                className="aspect-video w-full"
                role="img"
                aria-label="Live delivery map"
                preserveAspectRatio="xMidYMid meet"
            >
                <defs>
                    <pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse">
                        <path
                            d="M 10 0 L 0 0 0 10"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="0.25"
                            className="text-border"
                        />
                    </pattern>
                </defs>
                <rect width="100" height="100" fill="url(#grid)" />

                <line
                    x1={pct(position.pickup.x)}
                    y1={pct(position.pickup.y)}
                    x2={pct(position.drop.x)}
                    y2={pct(position.drop.y)}
                    stroke="currentColor"
                    strokeWidth="0.8"
                    strokeDasharray="2 2"
                    className="text-muted-foreground"
                />

                {/* Pickup */}
                <circle
                    cx={pct(position.pickup.x)}
                    cy={pct(position.pickup.y)}
                    r="2"
                    className="fill-muted-foreground"
                />
                {/* Drop */}
                <rect
                    x={pct(position.drop.x) - 2}
                    y={pct(position.drop.y) - 2}
                    width="4"
                    height="4"
                    className="fill-primary"
                />
                {/* Rider */}
                <circle
                    cx={pct(position.current.x)}
                    cy={pct(position.current.y)}
                    r="3"
                    className="fill-primary stroke-background"
                    strokeWidth="1"
                >
                    <animate
                        attributeName="r"
                        values="3;3.8;3"
                        dur="1.6s"
                        repeatCount="indefinite"
                    />
                </circle>
            </svg>
            <div className="flex items-center justify-between px-3 py-2 text-xs text-muted-foreground">
                <span>Pickup</span>
                <span>{Math.round(position.progress * 100)}% of the way</span>
                <span>Drop-off</span>
            </div>
        </div>
    );
}
