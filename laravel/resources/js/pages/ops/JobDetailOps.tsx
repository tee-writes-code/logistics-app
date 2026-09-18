import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { toast } from 'sonner';

import { AgentAskCard } from '@/components/AgentAskCard';
import { JobStatusBadge } from '@/components/JobStatusBadge';
import { JobTimeline } from '@/components/JobTimeline';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, get, patch, post } from '@/lib/api';
import type { AgentAsk, Job, JobWindow, RiderBoardEntry } from '@/lib/types';

const WINDOW_LABELS: Record<JobWindow, string> = {
    same_day: 'Same day',
    next: 'Next',
};

/** Ops job detail: edit on behalf, reassign, cancel, and resolve asks. */
export default function JobDetailOps() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [job, setJob] = useState<Job | null>(null);
    const [riders, setRiders] = useState<RiderBoardEntry[]>([]);
    const [asks, setAsks] = useState<AgentAsk[]>([]);
    const [instructions, setInstructions] = useState('');
    const [notes, setNotes] = useState('');
    const [riderId, setRiderId] = useState<string>('');

    const load = useCallback(() => {
        get<{ data: Job }>(`/ops/jobs/${id}`)
            .then((r) => {
                setJob(r.data);
                setInstructions(r.data.instructions ?? '');
                setNotes(r.data.notes ?? '');
            })
            .catch(() => undefined);
        get<{ data: AgentAsk[] }>('/agent-asks')
            .then((r) => setAsks(r.data.filter((a) => a.job_id === Number(id))))
            .catch(() => undefined);
    }, [id]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        get<{ data: RiderBoardEntry[] }>('/ops/riders').then((r) => setRiders(r.data)).catch(() => undefined);
    }, []);

    const save = async () => {
        try {
            await patch(`/ops/jobs/${id}`, { instructions, notes });
            toast.success('Job updated.');
            load();
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not save.');
        }
    };

    const reassign = async () => {
        if (riderId === '') return;
        try {
            await post(`/ops/jobs/${id}/assign`, { rider_id: Number(riderId) });
            toast.success('Reassigned.');
            load();
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not reassign.');
        }
    };

    const cancel = async () => {
        try {
            await post(`/ops/jobs/${id}/cancel`);
            toast.success('Job cancelled.');
            load();
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not cancel.');
        }
    };

    if (job === null) {
        return <p className="text-muted-foreground">Loading…</p>;
    }

    return (
        <div className="grid gap-6">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="sm" onClick={() => navigate('/ops/board')}>
                        ← Board
                    </Button>
                    <h1 className="text-2xl font-semibold tracking-tight">Job #{job.id}</h1>
                    <JobStatusBadge status={job.status} />
                </div>
                {job.can_cancel ? (
                    <Button variant="destructive" size="sm" onClick={cancel}>
                        Cancel job
                    </Button>
                ) : null}
            </div>

            {asks.length > 0 ? (
                <div className="grid gap-3">
                    {asks.map((ask) => (
                        <AgentAskCard key={ask.id} ask={ask} onResolved={load} />
                    ))}
                </div>
            ) : null}

            <div className="grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm">
                        <div><span className="font-medium">Drop:</span> {job.drop_address}</div>
                        <div><span className="font-medium">Contact:</span> {job.drop_contact_name} · {job.drop_contact_phone}</div>
                        <div><span className="font-medium">Rider:</span> {job.assigned_rider?.name ?? '—'}</div>
                        <div><span className="font-medium">Window:</span> {WINDOW_LABELS[job.window]}</div>
                        <div><span className="font-medium">ETA:</span> {job.eta_at ? new Date(job.eta_at).toLocaleString() : '—'}</div>

                        <div className="grid gap-2 pt-2">
                            <Label>Reassign rider</Label>
                            <div className="flex gap-2">
                                <Select
                                    value={riderId}
                                    onValueChange={(value) => setRiderId(value ?? '')}
                                    items={Object.fromEntries(
                                        riders.map((r) => [String(r.id), `${r.name} (${r.load})`]),
                                    )}
                                >
                                    <SelectTrigger className="flex-1"><SelectValue placeholder="Pick a rider" /></SelectTrigger>
                                    <SelectContent>
                                        {riders.map((r) => (
                                            <SelectItem key={r.id} value={String(r.id)}>{r.name} ({r.load})</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button onClick={reassign} disabled={riderId === ''}>Assign</Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Edit on behalf</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="instructions">Instructions</Label>
                            <Textarea id="instructions" value={instructions} onChange={(e) => setInstructions(e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea id="notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
                        </div>
                        <Button onClick={save} className="w-fit">Save</Button>
                    </CardContent>
                </Card>
            </div>

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
