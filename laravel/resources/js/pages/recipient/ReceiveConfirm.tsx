import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { ApiError, post } from '@/lib/api';
import type { DataResponse, RecipientView } from '@/lib/types';

/**
 * Confirms the recipient is ready to receive, while the rider is on site. This
 * records intent only; the rider's proof of delivery still closes the job.
 */
export function ReceiveConfirm({
    token,
    onUpdated,
}: {
    token: string;
    onUpdated: (view: RecipientView) => void;
}) {
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true);
        try {
            const response = await post<DataResponse<RecipientView>>(
                `/track/${token}/receive-confirm`,
            );
            onUpdated(response.data);
            toast.success('Thanks — the rider has been notified.');
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not confirm.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Button type="button" onClick={confirm} disabled={busy}>
            Confirm I'm ready to receive
        </Button>
    );
}
