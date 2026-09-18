import { Camera, Check, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { ApiError, post } from '@/lib/api';
import type { DataResponse, Job } from '@/lib/types';

/** A tiny placeholder image stands in for a real camera capture (mock). */
const MOCK_PHOTO =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * Completes a return-to-shop. The return photo is optional (spec override): the
 * job reaches `returned` with or without one.
 */
export function ReturnFlow({ jobId, onDone }: { jobId: number; onDone: (job: Job) => void }) {
    const [photo, setPhoto] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const complete = async () => {
        setSubmitting(true);
        try {
            const response = await post<DataResponse<Job>>(`/jobs/${jobId}/return-complete`, {
                return_photo: photo ?? undefined,
            });
            toast.success('Return completed.');
            onDone(response.data);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not complete the return.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <span className="text-sm font-medium">Return photo (optional · mock)</span>
                {photo ? (
                    <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
                        <span className="flex items-center gap-2 text-muted-foreground">
                            <Check className="size-4 text-primary" />
                            Photo attached
                        </span>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setPhoto(null)}>
                            Remove
                        </Button>
                    </div>
                ) : (
                    <Button type="button" variant="outline" onClick={() => setPhoto(MOCK_PHOTO)}>
                        <Camera className="size-4" />
                        Capture photo (mock)
                    </Button>
                )}
            </div>
            <Button type="button" onClick={complete} disabled={submitting}>
                <Undo2 className="size-4" />
                Complete return
            </Button>
        </div>
    );
}
