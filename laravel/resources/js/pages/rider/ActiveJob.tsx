import { ArrowLeft, ArrowRight, Ban, CircleAlert } from 'lucide-react';
import { useCallback, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import { JobTimeline } from '@/components/JobTimeline';
import { LiveMap } from '@/components/LiveMap';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ApiError, get, post } from '@/lib/api';
import { usePolling } from '@/lib/usePolling';
import { cn } from '@/lib/utils';
import type { DataResponse, Job, JobStatus } from '@/lib/types';

const POLL_MS = 5000;
import { FailDialog } from '@/pages/rider/FailDialog';
import { PodCapture } from '@/pages/rider/PodCapture';
import { ReturnFlow } from '@/pages/rider/ReturnFlow';

const STEPS: { status: JobStatus; label: string }[] = [
    { status: 'assigned', label: 'Assigned' },
    { status: 'en_route_pickup', label: 'To pickup' },
    { status: 'at_pickup', label: 'At pickup' },
    { status: 'picked_up', label: 'Collected' },
    { status: 'en_route_drop', label: 'To drop' },
    { status: 'on_site', label: 'On site' },
    { status: 'delivered', label: 'Delivered' },
];

const ADVANCE: Partial<Record<JobStatus, { label: string; path: string }>> = {
    assigned: { label: 'Start run', path: 'start' },
    en_route_pickup: { label: 'Arrive at pickup', path: 'arrive-pickup' },
    at_pickup: { label: 'Collect part', path: 'collect' },
    picked_up: { label: 'Depart for drop', path: 'depart-drop' },
    en_route_drop: { label: 'Arrive on site', path: 'arrive-site' },
};

function Stepper({ status }: { status: JobStatus }) {
    const currentIndex = STEPS.findIndex((step) => step.status === status);

    return (
        <ol className="flex items-center gap-1">
            {STEPS.map((step, index) => {
                const done = currentIndex >= 0 && index <= currentIndex;
                return (
                    <li key={step.status} className="flex flex-1 flex-col items-center gap-1">
                        <div
                            className={cn(
                                'h-1.5 w-full rounded-full',
                                done ? 'bg-primary' : 'bg-muted',
                            )}
                        />
                        <span
                            className={cn(
                                'text-[10px] leading-tight',
                                index === currentIndex
                                    ? 'font-semibold text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {step.label}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value || '—'}</dd>
        </div>
    );
}

export default function ActiveJob() {
    const { id } = useParams<{ id: string }>();
    const [job, setJob] = useState<Job | null>(null);
    const [notFound, setNotFound] = useState(false);
    const [advancing, setAdvancing] = useState(false);

    const load = useCallback(() => {
        if (!id) {
            return;
        }
        return get<DataResponse<Job>>(`/jobs/${id}`)
            .then((response) => setJob(response.data))
            .catch((error) => {
                if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
                    setNotFound(true);
                } else {
                    toast.error('Could not load the job.');
                }
            });
    }, [id]);

    // Poll the record so an ops reassign / recipient hold / dispatch change shows
    // up here without a manual reload; pause during a mutation and stop on
    // terminal/not-found so the stepper and action card never go stale.
    usePolling(load, POLL_MS, !notFound && !advancing && !(job?.is_terminal ?? false));

    const advance = async (path: string) => {
        if (!job) {
            return;
        }
        setAdvancing(true);
        try {
            const response = await post<DataResponse<Job>>(`/jobs/${job.id}/${path}`);
            setJob(response.data);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not update the job.');
        } finally {
            setAdvancing(false);
        }
    };

    if (notFound) {
        return (
            <div className="mx-auto grid max-w-md gap-4">
                <p className="text-muted-foreground">This job could not be found.</p>
                <Button
                    variant="outline"
                    className="w-fit"
                    render={
                        <Link to="/rider/queue">
                            <ArrowLeft className="size-4" />
                            Back to queue
                        </Link>
                    }
                />
            </div>
        );
    }

    if (!job) {
        return <p className="text-muted-foreground">Loading…</p>;
    }

    const advanceAction = ADVANCE[job.status];
    const isActive = job.is_active_for_rider && !job.is_terminal;

    return (
        <div className="mx-auto grid max-w-md gap-5">
            <div className="flex items-center gap-3">
                <Button
                    variant="ghost"
                    size="icon"
                    render={
                        <Link to="/rider/queue" aria-label="Back to queue">
                            <ArrowLeft className="size-4" />
                        </Link>
                    }
                />
                <h1 className="text-xl font-semibold tracking-tight">Job #{job.id}</h1>
                <JobStatusBadge status={job.status} />
            </div>

            <Stepper status={job.status} />

            <LiveMap jobId={job.id} terminal={job.is_terminal} />

            <Card>
                <CardHeader>
                    <CardTitle>Drop-off</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl className="grid gap-3">
                        <Field label="Address" value={job.drop_address} />
                        <Field label="Recipient" value={job.drop_contact_name} />
                        <Field label="Phone" value={job.drop_contact_phone} />
                        <Field label="Instructions" value={job.instructions} />
                        {job.part_line ? (
                            <Field
                                label="Part"
                                value={`${job.part_line.name} × ${job.part_line.quantity}`}
                            />
                        ) : null}
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Action</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3">
                    {job.status === 'delivered' || job.status === 'returned' || job.status === 'cancelled' ? (
                        <p className="text-sm text-muted-foreground">
                            This job is complete. Nothing more to do.
                        </p>
                    ) : !isActive ? (
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <CircleAlert className="mt-0.5 size-4 shrink-0" />
                            This isn't your active job yet. Finish the active job first.
                        </p>
                    ) : job.status === 'failed' ? (
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <Ban className="mt-0.5 size-4 shrink-0" />
                            Attempt recorded{job.fail_reason ? ` (${job.fail_reason})` : ''}. Awaiting
                            a new plan from ops before you can try again.
                        </p>
                    ) : job.status === 'returning' ? (
                        <ReturnFlow jobId={job.id} onDone={setJob} />
                    ) : job.status === 'on_site' ? (
                        <>
                            <PodCapture jobId={job.id} onDone={setJob} />
                            <FailDialog jobId={job.id} onDone={setJob} />
                        </>
                    ) : advanceAction ? (
                        <Button
                            type="button"
                            onClick={() => advance(advanceAction.path)}
                            disabled={advancing}
                        >
                            {advanceAction.label}
                            <ArrowRight className="size-4" />
                        </Button>
                    ) : (
                        <p className="text-sm text-muted-foreground">No action available.</p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Timeline</CardTitle>
                </CardHeader>
                <CardContent>
                    <JobTimeline events={job.timeline} />
                </CardContent>
            </Card>
        </div>
    );
}
