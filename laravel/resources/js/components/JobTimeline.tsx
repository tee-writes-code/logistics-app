import type { TimelineEvent } from '@/lib/types';

/**
 * Renders a job's append-only timeline in order. Reused by the rider active-job
 * screen and the customer/ops job detail views.
 */
export function JobTimeline({ events }: { events: TimelineEvent[] | undefined }) {
    if (!events || events.length === 0) {
        return <p className="text-muted-foreground">No events yet.</p>;
    }

    return (
        <ol className="grid gap-4">
            {events.map((event) => (
                <li key={event.id} className="flex gap-3">
                    <div className="mt-1 size-2 shrink-0 rounded-full bg-primary" />
                    <div>
                        <div className="text-sm font-medium">
                            {event.description ?? event.type}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {event.agent ?? event.actor_role ?? 'system'}
                            {event.created_at
                                ? ` · ${new Date(event.created_at).toLocaleString()}`
                                : ''}
                        </div>
                    </div>
                </li>
            ))}
        </ol>
    );
}
