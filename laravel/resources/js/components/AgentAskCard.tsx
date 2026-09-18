import { useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { ApiError, post } from '@/lib/api';
import type { AgentAsk } from '@/lib/types';

const TYPE_LABEL: Record<AgentAsk['type'], string> = {
    next: 'Reschedule to next window',
    return: 'Return the part to the shop',
    unsafe: 'Safety review',
};

/**
 * A single agent confirmation request with confirm/reject controls. A next-window
 * ask also offers a same-day reattempt (re-checked server-side); an unsafe ask
 * confirms it is safe to resume.
 */
export function AgentAskCard({ ask, onResolved }: { ask: AgentAsk; onResolved: () => void }) {
    const [busy, setBusy] = useState(false);

    const act = async (path: string, body?: unknown) => {
        setBusy(true);
        try {
            await post(`/agent-asks/${ask.id}/${path}`, body);
            toast.success('Request resolved.');
            onResolved();
        } catch (error) {
            const message =
                error instanceof ApiError ? error.message : 'Could not resolve the request.';
            toast.error(message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="text-base">
                        Job #{ask.job_id} — {TYPE_LABEL[ask.type]}
                    </CardTitle>
                    <Badge variant="secondary">{ask.agent}</Badge>
                </div>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
                {ask.job ? (
                    <p>
                        {ask.job.drop_address} · status {ask.job.status}
                    </p>
                ) : null}
            </CardContent>
            <CardFooter className="flex flex-wrap gap-2">
                {ask.type === 'next' ? (
                    <>
                        <Button size="sm" disabled={busy} onClick={() => act('confirm', { decision: 'reattempt' })}>
                            Reattempt today
                        </Button>
                        <Button size="sm" variant="secondary" disabled={busy} onClick={() => act('confirm', { decision: 'next' })}>
                            Next window
                        </Button>
                    </>
                ) : (
                    <Button size="sm" disabled={busy} onClick={() => act('confirm')}>
                        {ask.type === 'unsafe' ? 'Confirm safe to resume' : 'Confirm'}
                    </Button>
                )}
                <Button size="sm" variant="outline" disabled={busy} onClick={() => act('reject')}>
                    Reject
                </Button>
            </CardFooter>
        </Card>
    );
}
