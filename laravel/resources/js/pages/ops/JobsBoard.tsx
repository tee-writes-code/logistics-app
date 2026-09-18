import { ClipboardList } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { get } from '@/lib/api';
import type { Job, JobStatus, RiderBoardEntry } from '@/lib/types';

const STATUSES: JobStatus[] = [
    'booked', 'assigned', 'en_route_pickup', 'at_pickup', 'picked_up',
    'en_route_drop', 'on_site', 'delivered', 'failed', 'returning', 'returned', 'cancelled',
];

const ALL = 'all';

// Base UI's Select renders the raw value in the trigger unless it can map value
// to a label via `items`. These records keep the trigger label in sync with the
// SelectItem text below.
const statusItems: Record<string, string> = {
    [ALL]: 'All statuses',
    ...Object.fromEntries(STATUSES.map((s) => [s, s])),
};
const WINDOW_ITEMS: Record<string, string> = {
    [ALL]: 'All windows',
    same_day: 'Same day',
    next: 'Next',
};

/** The Ops jobs board: every job with status/rider/window filters. */
export default function JobsBoard() {
    const [jobs, setJobs] = useState<Job[]>([]);
    const [riders, setRiders] = useState<RiderBoardEntry[]>([]);
    const [status, setStatus] = useState<string>(ALL);
    const [riderId, setRiderId] = useState<string>(ALL);
    const [windowFilter, setWindowFilter] = useState<string>(ALL);

    const load = useCallback(() => {
        const params = new URLSearchParams();
        if (status !== ALL) params.set('status', status);
        if (riderId !== ALL) params.set('rider_id', riderId);
        if (windowFilter !== ALL) params.set('window', windowFilter);
        const query = params.toString();
        get<{ data: Job[] }>(`/ops/jobs${query ? `?${query}` : ''}`)
            .then((r) => setJobs(r.data))
            .catch(() => undefined);
    }, [status, riderId, windowFilter]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        get<{ data: RiderBoardEntry[] }>('/ops/riders')
            .then((r) => setRiders(r.data))
            .catch(() => undefined);
    }, []);

    const riderItems: Record<string, string> = {
        [ALL]: 'All riders',
        ...Object.fromEntries(riders.map((r) => [String(r.id), r.name])),
    };

    return (
        <div className="grid gap-6">
            <div className="flex items-center gap-3">
                <ClipboardList className="size-5 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">Jobs board</h1>
            </div>

            <div className="flex flex-wrap gap-3">
                <Select
                    value={status}
                    onValueChange={(value) => setStatus(value ?? ALL)}
                    items={statusItems}
                >
                    <SelectTrigger className="w-48"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All statuses</SelectItem>
                        {STATUSES.map((s) => (
                            <SelectItem key={s} value={s}>{s}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={riderId}
                    onValueChange={(value) => setRiderId(value ?? ALL)}
                    items={riderItems}
                >
                    <SelectTrigger className="w-48"><SelectValue placeholder="Rider" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All riders</SelectItem>
                        {riders.map((r) => (
                            <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={windowFilter}
                    onValueChange={(value) => setWindowFilter(value ?? ALL)}
                    items={WINDOW_ITEMS}
                >
                    <SelectTrigger className="w-48"><SelectValue placeholder="Window" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All windows</SelectItem>
                        <SelectItem value="same_day">Same day</SelectItem>
                        <SelectItem value="next">Next</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Job</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Window</TableHead>
                        <TableHead>Rider</TableHead>
                        <TableHead>Drop</TableHead>
                        <TableHead>Asks</TableHead>
                        <TableHead />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {jobs.map((job) => (
                        <TableRow key={job.id}>
                            <TableCell>#{job.id}</TableCell>
                            <TableCell><JobStatusBadge status={job.status} /></TableCell>
                            <TableCell>{job.window}</TableCell>
                            <TableCell>{job.assigned_rider?.name ?? '—'}</TableCell>
                            <TableCell className="max-w-56 truncate">{job.drop_address}</TableCell>
                            <TableCell>
                                {job.pending_asks && job.pending_asks.length > 0 ? (
                                    <Badge variant="destructive">{job.pending_asks.length}</Badge>
                                ) : (
                                    '—'
                                )}
                            </TableCell>
                            <TableCell>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    render={<Link to={`/ops/jobs/${job.id}`}>Open</Link>}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                    {jobs.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={7} className="text-center text-muted-foreground">
                                No jobs match these filters.
                            </TableCell>
                        </TableRow>
                    ) : null}
                </TableBody>
            </Table>
        </div>
    );
}
