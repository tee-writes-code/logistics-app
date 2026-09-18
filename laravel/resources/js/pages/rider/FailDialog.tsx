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
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, post } from '@/lib/api';
import type { DataResponse, FailReason, Job } from '@/lib/types';

const REASONS: { value: FailReason; label: string }[] = [
    { value: 'closed', label: 'Premises closed' },
    { value: 'wrong_site', label: 'Wrong site / bad address' },
    { value: 'no_contact', label: 'No contact / no answer' },
];

/**
 * Records a failed delivery attempt (reason + optional note). A failed job is
 * blocked from delivery until an Exception replan (iter-4).
 */
export function FailDialog({ jobId, onDone }: { jobId: number; onDone: (job: Job) => void }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState<FailReason | ''>('');
    const [note, setNote] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = async () => {
        if (!reason) {
            return;
        }
        setSubmitting(true);
        try {
            const response = await post<DataResponse<Job>>(`/jobs/${jobId}/fail`, {
                reason,
                note: note || undefined,
            });
            toast.success('Attempt recorded as failed.');
            setOpen(false);
            onDone(response.data);
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not record the failure.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger
                render={
                    <Button variant="destructive" className="w-full">
                        Can't deliver
                    </Button>
                }
            />
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a failed attempt</DialogTitle>
                    <DialogDescription>
                        Pick a reason. Ops will re-plan the job before another attempt.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <RadioGroup
                        value={reason}
                        onValueChange={(value) => setReason(value as FailReason)}
                    >
                        {REASONS.map((item) => (
                            <div key={item.value} className="flex items-center gap-3">
                                <RadioGroupItem value={item.value} id={`reason-${item.value}`} />
                                <Label htmlFor={`reason-${item.value}`}>{item.label}</Label>
                            </div>
                        ))}
                    </RadioGroup>
                    <Textarea
                        rows={2}
                        placeholder="Add a note (optional)"
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={submit}
                        disabled={!reason || submitting}
                    >
                        Record failure
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
