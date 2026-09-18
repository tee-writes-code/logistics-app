import { ChevronRight, MapPin, PackageCheck } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import { Card, CardContent } from '@/components/ui/card';
import { get } from '@/lib/api';
import { usePolling } from '@/lib/usePolling';
import { cn } from '@/lib/utils';
import type { DataResponse, Job } from '@/lib/types';

const POLL_MS = 10000;

/**
 * The rider's mobile run queue: their own jobs in order, with the single active
 * job highlighted at the top. Tapping a job opens its execution screen.
 */
export default function RiderQueue() {
    const [jobs, setJobs] = useState<Job[] | null>(null);
    const navigate = useNavigate();

    const load = useCallback(
        () =>
            get<DataResponse<Job[]>>('/rider/queue')
                .then((response) => setJobs(response.data))
                .catch(() => {
                    toast.error('Could not load your queue.');
                }),
        [],
    );

    // Light poll so newly assigned/reordered runs surface without a manual reload.
    usePolling(load, POLL_MS);

    return (
        <div className="mx-auto grid max-w-md gap-4">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">My runs</h1>
                <p className="text-muted-foreground">Your assigned jobs, in order.</p>
            </div>

            {jobs === null ? (
                <p className="text-muted-foreground">Loading…</p>
            ) : jobs.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
                        <PackageCheck className="size-8 text-muted-foreground" />
                        <p className="font-medium">Your queue is empty</p>
                        <p className="text-sm text-muted-foreground">
                            New runs will appear here once dispatch assigns them.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-3">
                    {jobs.map((job) => (
                        <Card
                            key={job.id}
                            onClick={() => navigate(`/rider/jobs/${job.id}`)}
                            className={cn(
                                'cursor-pointer transition-colors hover:bg-accent/40',
                                job.is_active_for_rider && 'border-primary ring-1 ring-primary',
                            )}
                        >
                            <CardContent className="flex items-center gap-3 py-4">
                                <div className="flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">Job #{job.id}</span>
                                        {job.is_active_for_rider ? (
                                            <span className="rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary-foreground uppercase">
                                                Active
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="mt-1 flex items-start gap-1 text-sm text-muted-foreground">
                                        <MapPin className="mt-0.5 size-3.5 shrink-0" />
                                        {job.drop_address}
                                    </div>
                                    <div className="mt-2">
                                        <JobStatusBadge status={job.status} />
                                    </div>
                                </div>
                                <ChevronRight className="size-5 shrink-0 text-muted-foreground" />
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}
