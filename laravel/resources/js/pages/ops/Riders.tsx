import { Bike } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';

import { RiderQueueList } from '@/components/RiderQueueList';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ApiError, get, post } from '@/lib/api';
import { usePolling } from '@/lib/usePolling';
import type { RiderBoardEntry } from '@/lib/types';

const POLL_MS = 10000;

/** The Ops rider board: each rider with a drag-reorderable live queue. */
export default function Riders() {
    const [riders, setRiders] = useState<RiderBoardEntry[]>([]);

    const load = useCallback(
        () =>
            get<{ data: RiderBoardEntry[] }>('/ops/riders')
                .then((r) => setRiders(r.data))
                .catch(() => undefined),
        [],
    );

    // Light poll so live loads / queue changes stay current; RiderQueueList
    // skips its re-sync mid-drag, so this never clobbers an in-progress reorder.
    usePolling(load, POLL_MS);

    const reorder = async (riderId: number, orderedIds: number[]) => {
        try {
            await post(`/ops/riders/${riderId}/queue/reorder`, { job_ids: orderedIds });
            toast.success('Queue reordered.');
            // Await the refetch so the list's optimistic-hold resolves only once
            // the refreshed queue reflects the new order (or the old, on failure).
            await load();
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not reorder the queue.');
            await load();
        }
    };

    return (
        <div className="grid gap-6">
            <div className="flex items-center gap-3">
                <Bike className="size-5 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">Riders</h1>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                {riders.map((rider) => (
                    <Card key={rider.id}>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between">
                                <span>{rider.name}</span>
                                <span className="text-sm font-normal text-muted-foreground">
                                    {rider.load} live
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <RiderQueueList
                                jobs={rider.queue}
                                onReorder={(ids) => reorder(rider.id, ids)}
                            />
                        </CardContent>
                    </Card>
                ))}
                {riders.length === 0 ? (
                    <p className="text-muted-foreground">No riders.</p>
                ) : null}
            </div>
        </div>
    );
}
