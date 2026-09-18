import { Camera, Check, PackageCheck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { SignaturePad } from '@/components/SignaturePad';
import { Button } from '@/components/ui/button';
import { ApiError, post } from '@/lib/api';
import type { DataResponse, Job } from '@/lib/types';

/** A tiny placeholder image stands in for a real camera capture (mock). */
const MOCK_PHOTO =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * Proof-of-delivery capture: a required signature and an optional (mock) photo.
 * The deliver button is disabled until a signature exists (spec override:
 * signature required, photo optional).
 */
export function PodCapture({ jobId, onDone }: { jobId: number; onDone: (job: Job) => void }) {
    const [signature, setSignature] = useState<string | null>(null);
    const [photo, setPhoto] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const deliver = async () => {
        if (!signature) {
            return;
        }
        setSubmitting(true);
        try {
            const response = await post<DataResponse<Job>>(`/jobs/${jobId}/deliver`, {
                signature,
                photo: photo ?? undefined,
            });
            toast.success('Delivered. POD captured.');
            onDone(response.data);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not record the delivery.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <span className="text-sm font-medium">Recipient signature (required)</span>
                <SignaturePad onChange={setSignature} />
            </div>

            <div className="grid gap-2">
                <span className="text-sm font-medium">Delivery photo (optional · mock)</span>
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

            <Button type="button" onClick={deliver} disabled={!signature || submitting}>
                <PackageCheck className="size-4" />
                Mark delivered
            </Button>
        </div>
    );
}
