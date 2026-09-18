import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, post } from '@/lib/api';
import type { DataResponse, RecipientView } from '@/lib/types';

/**
 * Refuse the delivery with a reason, while the rider is on site. This drives the
 * job to `failed` on the server and blocks a later rider delivery.
 */
export function RefuseDialog({
    token,
    onUpdated,
}: {
    token: string;
    onUpdated: (view: RecipientView) => void;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const refuse = async () => {
        if (reason.trim() === '') {
            return;
        }
        setBusy(true);
        try {
            const response = await post<DataResponse<RecipientView>>(`/track/${token}/refuse`, {
                reason,
            });
            onUpdated(response.data);
            setOpen(false);
            toast.success('Delivery refused.');
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not refuse the delivery.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger
                render={
                    <Button type="button" variant="destructive">
                        Refuse delivery
                    </Button>
                }
            />
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Refuse this delivery?</DialogTitle>
                    <DialogDescription>
                        Tell the rider why. The delivery will be marked as failed.
                    </DialogDescription>
                </DialogHeader>
                <Textarea
                    rows={3}
                    value={reason}
                    placeholder="e.g. Wrong part was ordered."
                    onChange={(event) => setReason(event.target.value)}
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={refuse}
                        disabled={busy || reason.trim() === ''}
                    >
                        Refuse delivery
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
