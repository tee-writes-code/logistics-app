import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { ApiError, del, post } from '@/lib/api';
import type { DataResponse, RecipientView } from '@/lib/types';

/**
 * Place or release a hold on the delivery. Available until the rider is on site;
 * the server re-checks the window and returns the refreshed view.
 */
export function HoldControls({
    token,
    active,
    onUpdated,
}: {
    token: string;
    active: boolean;
    onUpdated: (view: RecipientView) => void;
}) {
    const [busy, setBusy] = useState(false);

    const run = async (fn: () => Promise<DataResponse<RecipientView>>, message: string) => {
        setBusy(true);
        try {
            const response = await fn();
            onUpdated(response.data);
            toast.success(message);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not update the hold.');
        } finally {
            setBusy(false);
        }
    };

    if (active) {
        return (
            <div className="grid gap-2">
                <p className="text-sm text-muted-foreground">
                    A hold is in place. The rider will wait for your go-ahead.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={() =>
                        run(() => del<DataResponse<RecipientView>>(`/track/${token}/hold`), 'Hold released.')
                    }
                >
                    Release hold
                </Button>
            </div>
        );
    }

    return (
        <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() =>
                run(() => post<DataResponse<RecipientView>>(`/track/${token}/hold`, {}), 'Hold placed.')
            }
        >
            Place a hold
        </Button>
    );
}
