import { PackageCheck } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useParams } from 'react-router-dom';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import { LiveMap } from '@/components/LiveMap';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { get } from '@/lib/api';
import type { DataResponse, RecipientView } from '@/lib/types';
import { usePolling } from '@/lib/usePolling';
import { HoldControls } from '@/pages/recipient/HoldControls';
import { InstructionsForm } from '@/pages/recipient/InstructionsForm';
import { ReceiveConfirm } from '@/pages/recipient/ReceiveConfirm';
import { RefuseDialog } from '@/pages/recipient/RefuseDialog';

const POLL_MS = 8000;

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value || '—'}</dd>
        </div>
    );
}

/**
 * Unauthenticated recipient view of a single job, opened from a magic link at
 * /#/track/:token. No shell, no sign-in: the token is the only credential.
 * Renders a graceful read-only state for an expired/revoked/terminal link.
 */
export default function Track() {
    const { token } = useParams<{ token: string }>();
    const [view, setView] = useState<RecipientView | null>(null);
    const [loaded, setLoaded] = useState(false);

    const load = useCallback(() => {
        if (!token) {
            return;
        }
        return get<DataResponse<RecipientView>>(`/track/${token}`)
            .then((response) => setView(response.data))
            .catch(() => setView({ valid: false }))
            .finally(() => setLoaded(true));
    }, [token]);

    // Stop polling once the delivery is terminal (delivered/returned/cancelled):
    // a completed job's record never changes, so continuing to hit the endpoint
    // is pure waste. usePolling still runs the immediate initial fetch (`view`
    // is null on mount, so `terminal` is false) before halting.
    const terminal = view?.job?.is_terminal ?? false;
    usePolling(load, POLL_MS, !terminal);

    if (!loaded) {
        return (
            <div className="flex min-h-svh items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    if (!view || !view.valid || !view.job || !token) {
        return (
            <div className="mx-auto flex min-h-svh max-w-md flex-col justify-center p-4">
                <Alert>
                    <AlertTitle>This tracking link is no longer active</AlertTitle>
                    <AlertDescription>
                        The delivery may be complete, or the link may have expired. Please contact the
                        sender for an up-to-date link.
                    </AlertDescription>
                </Alert>
            </div>
        );
    }

    const { job, can, hold } = view;
    const eta = job.eta_at ? new Date(job.eta_at).toLocaleString() : null;
    const showActions =
        can && (can.instruct || can.hold || can.receive_confirm || can.refuse);

    return (
        <div className="mx-auto grid max-w-md gap-5 p-4 pb-10">
            <header className="flex items-center gap-2 pt-2">
                <PackageCheck className="size-5 text-primary" />
                <span className="font-semibold">Your delivery</span>
                <span className="ml-auto">
                    <JobStatusBadge status={job.status} />
                </span>
            </header>

            {view.map_eligible ? (
                <LiveMap jobId={job.id} token={token} terminal={job.is_terminal} />
            ) : (
                <Alert>
                    <AlertTitle>Status and ETA</AlertTitle>
                    <AlertDescription>
                        The live map appears once your rider has collected the part.
                    </AlertDescription>
                </Alert>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>Delivery</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl className="grid gap-3">
                        <Field label="Estimated arrival" value={eta} />
                        <Field label="Recipient" value={job.drop_contact_name} />
                        <Field label="Address" value={job.drop_address} />
                        {job.part_line ? (
                            <Field
                                label="Part"
                                value={`${job.part_line.name} × ${job.part_line.quantity}`}
                            />
                        ) : null}
                    </dl>
                </CardContent>
            </Card>

            {showActions ? (
                <Card>
                    <CardHeader>
                        <CardTitle>What would you like to do?</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        {can?.receive_confirm ? (
                            <ReceiveConfirm token={token} onUpdated={setView} />
                        ) : null}
                        {can?.refuse ? <RefuseDialog token={token} onUpdated={setView} /> : null}

                        {can?.instruct ? (
                            <>
                                {can.receive_confirm || can.refuse ? <Separator /> : null}
                                <InstructionsForm
                                    token={token}
                                    current={view.instruction}
                                    onUpdated={setView}
                                />
                            </>
                        ) : null}
                        {can?.hold ? (
                            <HoldControls
                                token={token}
                                active={hold?.active ?? false}
                                onUpdated={setView}
                            />
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
