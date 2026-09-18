import {
    DndContext,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { useRef, useState } from 'react';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import type { Job } from '@/lib/types';

/**
 * Fingerprint of the fields this list renders (order + the mutable bits), so a
 * refetch that changes a status/active flag/address re-syncs even when the id
 * order is unchanged.
 */
function signature(jobs: Job[]): string {
    return jobs
        .map((j) => `${j.id}:${j.status}:${j.is_active_for_rider ? 1 : 0}:${j.drop_address}`)
        .join('|');
}

function SortableRow({ job }: { job: Job }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: job.id,
    });

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`flex items-center gap-3 rounded-md border bg-card p-3 ${isDragging ? 'opacity-60' : ''}`}
        >
            <button
                type="button"
                className="cursor-grab text-muted-foreground"
                aria-label="Drag to reorder"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
            <div className="grid flex-1 gap-0.5">
                <span className="text-sm font-medium">Job #{job.id}</span>
                <span className="truncate text-xs text-muted-foreground">{job.drop_address}</span>
            </div>
            {job.is_active_for_rider ? (
                <span className="text-xs font-medium text-primary">Active</span>
            ) : null}
            <JobStatusBadge status={job.status} />
        </li>
    );
}

/**
 * A drag-reorderable list of a rider's live queue. Reordering emits the full
 * ordered id list; the active job must stay first (the server rejects otherwise).
 */
export function RiderQueueList({
    jobs,
    onReorder,
}: {
    jobs: Job[];
    onReorder: (orderedIds: number[]) => void | Promise<void>;
}) {
    const [items, setItems] = useState<Job[]>(jobs);
    const dragging = useRef(false);
    // Count of in-flight reorder round-trips (post + refetch). A refcount, not a
    // boolean: two quick successive reorders overlap, and clearing on the first
    // to settle would let the re-sync revert the second's optimistic move to the
    // stale `jobs` order until its own round-trip lands (an order2→order1→order2
    // flicker). The re-sync is suppressed while this is > 0.
    const reordering = useRef(0);
    const [, forceResync] = useState(0);
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));

    // Re-sync local state whenever any rendered field changes (order, status,
    // active flag, address) — but never mid-drag (would clobber the held row) and
    // never mid-reorder (the prop still holds the old order; syncing would revert
    // the optimistic move until the server round-trip lands).
    if (!dragging.current && reordering.current === 0 && signature(items) !== signature(jobs)) {
        setItems(jobs);
    }

    const handleDragEnd = (event: DragEndEvent) => {
        dragging.current = false;
        const { active, over } = event;
        if (over === null || active.id === over.id) {
            return;
        }
        const oldIndex = items.findIndex((j) => j.id === active.id);
        const newIndex = items.findIndex((j) => j.id === over.id);
        const next = arrayMove(items, oldIndex, newIndex);

        // The active job is pinned first (server invariant). Reject any move that
        // would displace it — dragging it down, or dragging another row above it —
        // so the client UX matches the server instead of optimistically snapping back.
        const activeJob = items.find((j) => j.is_active_for_rider);
        if (activeJob && next[0]?.id !== activeJob.id) {
            return;
        }

        reordering.current += 1;
        setItems(next);
        // Hold the optimistic order until the round-trip settles. On success the
        // refreshed `jobs` matches `next`, so the resync is a no-op (or picks up
        // server field changes); on failure `jobs` still holds the old order, so
        // the resync reverts to server truth. Either way, force one render after
        // clearing the guard so that reconciliation runs.
        Promise.resolve(onReorder(next.map((j) => j.id))).finally(() => {
            reordering.current -= 1;
            forceResync((n) => n + 1);
        });
    };

    if (items.length === 0) {
        return <p className="text-sm text-muted-foreground">No live jobs.</p>;
    }

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragStart={() => {
                dragging.current = true;
            }}
            onDragCancel={() => {
                dragging.current = false;
            }}
            onDragEnd={handleDragEnd}
        >
            <SortableContext items={items.map((j) => j.id)} strategy={verticalListSortingStrategy}>
                <ul className="grid gap-2">
                    {items.map((job) => (
                        <SortableRow key={job.id} job={job} />
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}
