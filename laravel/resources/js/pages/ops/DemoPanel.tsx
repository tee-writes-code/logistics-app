import { AlertTriangle, Clock, Copy, FlaskConical, MessageSquare, RotateCcw, Truck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ApiError, del, get, post } from '@/lib/api';
import type { AppNotification, DataResponse } from '@/lib/types';

type ClockPreset = 'before_cutoff' | 'after_cutoff';

interface ClockOverride {
    offset_seconds: number;
    preset: ClockPreset | null;
    target: string | null;
    set_at: string | null;
}

interface ClockState {
    now: string;
    override: ClockOverride | null;
}

const PRESET_LABELS: Record<ClockPreset, string> = {
    before_cutoff: 'Before cutoff (hours remaining)',
    after_cutoff: 'After cutoff (no hours)',
};

const SMS_POLL_MS = 10000;

/**
 * Ops-only demo operator panel (iter-5). Sets the demo clock (advancing offset)
 * via presets, triggers a delay or a force-miss-hours on a job, embeds the mock
 * SMS outbox, and resets the fixtures. Every action calls a guarded /api/demo/*
 * endpoint; this panel adds no product behaviour of its own.
 */
export default function DemoPanel() {
    const [clock, setClock] = useState<ClockState | null>(null);
    const [messages, setMessages] = useState<AppNotification[]>([]);
    const [delayJobId, setDelayJobId] = useState('');
    const [missJobId, setMissJobId] = useState('');
    const [busy, setBusy] = useState(false);

    const loadClock = useCallback(() => {
        get<DataResponse<ClockState>>('/demo/clock')
            .then((response) => setClock(response.data))
            .catch(() => {
                /* surfaced by api.ts */
            });
    }, []);

    const loadSms = useCallback(() => {
        get<DataResponse<AppNotification[]>>('/demo/sms-outbox')
            .then((response) => setMessages(response.data))
            .catch(() => {
                /* surfaced by api.ts */
            });
    }, []);

    useEffect(() => {
        loadClock();
        loadSms();
        const timer = setInterval(loadSms, SMS_POLL_MS);
        return () => clearInterval(timer);
    }, [loadClock, loadSms]);

    const applyPreset = async (preset: ClockPreset) => {
        setBusy(true);
        try {
            const response = await post<DataResponse<ClockState>>('/demo/clock', { preset });
            setClock(response.data);
            toast.success(`Clock set: ${PRESET_LABELS[preset]}.`);
        } catch {
            toast.error('Could not set the clock.');
        } finally {
            setBusy(false);
        }
    };

    const clearClock = async () => {
        setBusy(true);
        try {
            const response = await del<DataResponse<ClockState>>('/demo/clock');
            setClock(response.data);
            toast.success('Clock override cleared.');
        } catch {
            toast.error('Could not clear the clock.');
        } finally {
            setBusy(false);
        }
    };

    const trigger = async (kind: 'delay' | 'force-miss-hours', rawId: string) => {
        const id = Number.parseInt(rawId, 10);
        if (!Number.isInteger(id) || id <= 0) {
            toast.error('Enter a valid job number.');
            return;
        }
        setBusy(true);
        try {
            await post(`/demo/jobs/${id}/${kind}`);
            toast.success(kind === 'delay' ? 'Delay triggered.' : 'Forced miss-hours.');
            loadClock();
            loadSms();
        } catch (error) {
            const message =
                error instanceof ApiError && error.status === 409
                    ? error.message
                    : 'The trigger could not run.';
            toast.error(message);
        } finally {
            setBusy(false);
        }
    };

    const resetFixtures = async () => {
        setBusy(true);
        try {
            const response = await post<DataResponse<ClockState>>('/demo/reset');
            setClock(response.data);
            setDelayJobId('');
            setMissJobId('');
            loadSms();
            toast.success('Fixtures reset to the seeded state.');
        } catch {
            toast.error('Could not reset the fixtures.');
        } finally {
            setBusy(false);
        }
    };

    const copy = async (link: string) => {
        try {
            await navigator.clipboard.writeText(link);
            toast.success('Link copied.');
        } catch {
            toast.error('Could not copy the link.');
        }
    };

    const override = clock?.override ?? null;
    const activePreset = override?.preset ?? '';

    return (
        <div className="grid gap-6">
            <div className="flex items-center gap-3">
                <FlaskConical className="size-5 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">Demo controls</h1>
            </div>
            <p className="text-sm text-muted-foreground">
                Operator-only, non-production controls to run the scripted demo paths.
            </p>

            {override ? (
                <Alert>
                    <AlertTriangle className="size-4" />
                    <AlertTitle>Demo clock override is active</AlertTitle>
                    <AlertDescription>
                        Simulated time is now{' '}
                        <span className="font-medium">
                            {clock ? new Date(clock.now).toLocaleString() : '—'}
                        </span>
                        {override.preset ? ` · ${PRESET_LABELS[override.preset]}` : ''}. The clock
                        keeps ticking from here.
                    </AlertDescription>
                </Alert>
            ) : null}

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Clock className="size-4" /> Demo clock
                    </CardTitle>
                    <CardDescription>
                        Presets flip the booking gate: before the cutoff a same-day job assigns;
                        after it, only a next-window offer.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4">
                    <div className="flex items-center justify-between gap-4">
                        <Label htmlFor="clock-override" className="flex flex-col items-start gap-1">
                            <span>Clock override</span>
                            <span className="text-xs font-normal text-muted-foreground">
                                {override ? 'On — using a simulated clock' : 'Off — real time'}
                            </span>
                        </Label>
                        <Switch
                            id="clock-override"
                            checked={override !== null}
                            disabled={busy}
                            onCheckedChange={(checked) =>
                                checked ? applyPreset('before_cutoff') : clearClock()
                            }
                        />
                    </div>

                    <ToggleGroup
                        variant="outline"
                        value={activePreset ? [activePreset] : []}
                        disabled={busy}
                        onValueChange={(value) => {
                            const next = value[0];
                            if (next) {
                                applyPreset(next as ClockPreset);
                            }
                        }}
                    >
                        <ToggleGroupItem value="before_cutoff">Before cutoff</ToggleGroupItem>
                        <ToggleGroupItem value="after_cutoff">After cutoff</ToggleGroupItem>
                    </ToggleGroup>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Truck className="size-4" /> Exception triggers
                    </CardTitle>
                    <CardDescription>
                        Delay reassigns a rider's job with no confirm. Force miss-hours (after a
                        failed attempt) raises the next/return confirm ask.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="delay-job">Trigger delay on job</Label>
                        <div className="flex gap-2">
                            <Input
                                id="delay-job"
                                inputMode="numeric"
                                placeholder="Job #"
                                value={delayJobId}
                                onChange={(event) => setDelayJobId(event.target.value)}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => trigger('delay', delayJobId)}
                            >
                                Trigger delay
                            </Button>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="miss-job">Force miss-hours on job</Label>
                        <div className="flex gap-2">
                            <Input
                                id="miss-job"
                                inputMode="numeric"
                                placeholder="Job #"
                                value={missJobId}
                                onChange={(event) => setMissJobId(event.target.value)}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => trigger('force-miss-hours', missJobId)}
                            >
                                Force miss-hours
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <MessageSquare className="size-4" /> Mock SMS outbox
                    </CardTitle>
                    <CardDescription>
                        Recipient messages the system "sent" — not delivered, each carrying a
                        tracking link.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {messages.length === 0 ? (
                        <p className="text-muted-foreground">No messages yet.</p>
                    ) : (
                        <ScrollArea className="max-h-80">
                            <ul className="grid gap-3">
                                {messages.map((message) => (
                                    <li key={message.id} className="rounded-lg border p-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="grid gap-1">
                                                <span className="text-sm">{message.message}</span>
                                                {message.job_id ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Job #{message.job_id}
                                                    </span>
                                                ) : null}
                                            </div>
                                            {message.magic_link ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="shrink-0"
                                                    onClick={() =>
                                                        copy(message.magic_link as string)
                                                    }
                                                >
                                                    <Copy className="size-3.5" />
                                                    Copy link
                                                </Button>
                                            ) : null}
                                        </div>
                                        {message.magic_link ? (
                                            <p className="mt-2 truncate text-xs text-muted-foreground">
                                                {message.magic_link}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </ScrollArea>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <RotateCcw className="size-4" /> Fixture reset
                    </CardTitle>
                    <CardDescription>
                        Truncate the demo data, re-seed the foundation accounts and jobs, and clear
                        the clock override.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <AlertDialog>
                        <AlertDialogTrigger
                            render={
                                <Button type="button" variant="destructive" disabled={busy}>
                                    Reset fixtures
                                </Button>
                            }
                        />
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Reset the demo fixtures?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    This clears all demo jobs and re-seeds the demo accounts and
                                    sample jobs. It also clears any clock override. This cannot be
                                    undone.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <AlertDialogAction onClick={resetFixtures}>
                                    Reset
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </CardContent>
            </Card>
        </div>
    );
}
