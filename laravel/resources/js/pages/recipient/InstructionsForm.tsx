import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, post } from '@/lib/api';
import type { DataResponse, RecipientView } from '@/lib/types';

/**
 * Lets the recipient leave delivery instructions. Available until the rider is
 * on site; the server re-checks the window and returns the refreshed view.
 */
export function InstructionsForm({
    token,
    current,
    onUpdated,
}: {
    token: string;
    current: string | null | undefined;
    onUpdated: (view: RecipientView) => void;
}) {
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async () => {
        if (body.trim() === '') {
            return;
        }
        setSaving(true);
        try {
            const response = await post<DataResponse<RecipientView>>(
                `/track/${token}/instructions`,
                { body },
            );
            onUpdated(response.data);
            setBody('');
            toast.success('Instructions sent to the rider.');
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not save instructions.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="grid gap-2">
            {current ? (
                <p className="text-sm">
                    <span className="text-muted-foreground">Current: </span>
                    {current}
                </p>
            ) : null}
            <Textarea
                rows={3}
                value={body}
                placeholder="e.g. Leave at the front desk, call on arrival."
                onChange={(event) => setBody(event.target.value)}
            />
            <Button type="button" onClick={submit} disabled={saving || body.trim() === ''}>
                Send instructions
            </Button>
        </div>
    );
}
