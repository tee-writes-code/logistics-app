import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { z } from 'zod';

import { JobStatusBadge } from '@/components/JobStatusBadge';
import { LiveMap } from '@/components/LiveMap';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, get, patch, post } from '@/lib/api';
import { usePolling } from '@/lib/usePolling';
import type { DataResponse, Job } from '@/lib/types';

const POLL_MS = 5000;

const editSchema = z.object({
    drop_address: z.string().min(1, 'Drop-off address is required.'),
    drop_contact_name: z.string().min(1, 'Recipient name is required.'),
    drop_phone: z.string().min(1, 'Recipient phone is required.'),
    instructions: z.string().optional(),
    notes: z.string().optional(),
});

type EditValues = z.infer<typeof editSchema>;

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value || '—'}</dd>
        </div>
    );
}

export default function JobDetail() {
    const { id } = useParams<{ id: string }>();
    const [job, setJob] = useState<Job | null>(null);
    const [notFound, setNotFound] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const form = useForm<EditValues>({ resolver: zodResolver(editSchema) });

    const load = useCallback(() => {
        if (!id) {
            return;
        }
        return get<DataResponse<Job>>(`/jobs/${id}`)
            .then((response) => setJob(response.data))
            .catch((error) => {
                if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
                    setNotFound(true);
                } else {
                    toast.error('Could not load the job.');
                }
            });
    }, [id]);

    // Poll the record so another actor's change (ops reassign, recipient hold,
    // dispatch advance) reflects here; stop once terminal, not found, or editing.
    usePolling(load, POLL_MS, !notFound && !editOpen && !(job?.is_terminal ?? false));

    const openEdit = () => {
        if (!job) {
            return;
        }
        form.reset({
            drop_address: job.drop_address,
            drop_contact_name: job.drop_contact_name,
            drop_phone: job.drop_contact_phone,
            instructions: job.instructions ?? '',
            notes: job.notes ?? '',
        });
        setEditOpen(true);
    };

    const onSave = async (values: EditValues) => {
        if (!job) {
            return;
        }
        try {
            const response = await patch<DataResponse<Job>>(`/jobs/${job.id}`, values);
            setJob(response.data);
            setEditOpen(false);
            toast.success('Job updated.');
        } catch (error) {
            if (error instanceof ApiError && error.errors) {
                for (const [field, messages] of Object.entries(error.errors)) {
                    form.setError(field as keyof EditValues, { message: messages[0] });
                }
            } else {
                toast.error(error instanceof ApiError ? error.message : 'Could not update the job.');
            }
        }
    };

    const cancelJob = async () => {
        if (!job) {
            return;
        }
        try {
            const response = await post<DataResponse<Job>>(`/jobs/${job.id}/cancel`);
            setJob(response.data);
            toast.success('Job cancelled.');
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not cancel the job.');
        }
    };

    if (notFound) {
        return (
            <div className="grid gap-4">
                <p className="text-muted-foreground">This job could not be found.</p>
                <Button
                    variant="outline"
                    className="w-fit"
                    render={
                        <Link to="/jobs">
                            <ArrowLeft className="size-4" />
                            Back to jobs
                        </Link>
                    }
                />
            </div>
        );
    }

    if (!job) {
        return <p className="text-muted-foreground">Loading…</p>;
    }

    const canEdit = job.can_edit_drop || job.can_edit_notes;

    return (
        <div className="grid gap-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        render={
                            <Link to="/jobs" aria-label="Back to jobs">
                                <ArrowLeft className="size-4" />
                            </Link>
                        }
                    />
                    <h1 className="text-2xl font-semibold tracking-tight">Job #{job.id}</h1>
                    <JobStatusBadge status={job.status} />
                </div>
                <div className="flex gap-2">
                    {canEdit ? (
                        <Dialog open={editOpen} onOpenChange={setEditOpen}>
                            <DialogTrigger
                                render={
                                    <Button variant="outline" onClick={openEdit}>
                                        Edit
                                    </Button>
                                }
                            />
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Edit job</DialogTitle>
                                    <DialogDescription>
                                        {job.can_edit_drop
                                            ? 'Drop-off and notes can be edited.'
                                            : 'Only notes can be edited at this stage.'}
                                    </DialogDescription>
                                </DialogHeader>
                                <Form {...form}>
                                    <form
                                        className="grid gap-4"
                                        onSubmit={form.handleSubmit(onSave)}
                                    >
                                        <FormField
                                            control={form.control}
                                            name="drop_address"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Drop-off address</FormLabel>
                                                    <FormControl>
                                                        <Textarea
                                                            rows={2}
                                                            disabled={!job.can_edit_drop}
                                                            {...field}
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name="drop_contact_name"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Recipient name</FormLabel>
                                                    <FormControl>
                                                        <Input
                                                            disabled={!job.can_edit_drop}
                                                            {...field}
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name="drop_phone"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Recipient phone</FormLabel>
                                                    <FormControl>
                                                        <Input
                                                            disabled={!job.can_edit_drop}
                                                            {...field}
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name="instructions"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Instructions</FormLabel>
                                                    <FormControl>
                                                        <Textarea
                                                            rows={2}
                                                            disabled={!job.can_edit_drop}
                                                            {...field}
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name="notes"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Notes</FormLabel>
                                                    <FormControl>
                                                        <Textarea
                                                            rows={2}
                                                            disabled={!job.can_edit_notes}
                                                            {...field}
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <DialogFooter>
                                            <Button
                                                type="submit"
                                                disabled={form.formState.isSubmitting}
                                            >
                                                Save changes
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </Form>
                            </DialogContent>
                        </Dialog>
                    ) : null}

                    {job.can_cancel ? (
                        <AlertDialog>
                            <AlertDialogTrigger
                                render={<Button variant="destructive">Cancel job</Button>}
                            />
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Cancel this job?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        This cannot be undone. The job will be marked cancelled.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Keep job</AlertDialogCancel>
                                    <AlertDialogAction onClick={cancelJob}>
                                        Cancel job
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    ) : null}
                </div>
            </div>

            <LiveMap jobId={job.id} terminal={job.is_terminal} />

            <div className="grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Drop-off</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-3">
                            <Field label="Address" value={job.drop_address} />
                            <Field label="Recipient" value={job.drop_contact_name} />
                            <Field label="Phone" value={job.drop_contact_phone} />
                            <Field label="Instructions" value={job.instructions} />
                            <Field label="Notes" value={job.notes} />
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Part</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {job.part_line ? (
                            <dl className="grid gap-3">
                                <Field label="Name" value={job.part_line.name} />
                                <Field label="SKU" value={job.part_line.sku} />
                                <Field label="Quantity" value={String(job.part_line.quantity)} />
                                <Field label="Serial" value={job.part_line.serial} />
                            </dl>
                        ) : (
                            <p className="text-muted-foreground">No part line.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Timeline</CardTitle>
                </CardHeader>
                <CardContent>
                    {job.timeline && job.timeline.length > 0 ? (
                        <ol className="grid gap-4">
                            {job.timeline.map((event) => (
                                <li key={event.id} className="flex gap-3">
                                    <div className="mt-1 size-2 shrink-0 rounded-full bg-primary" />
                                    <div>
                                        <div className="text-sm font-medium">
                                            {event.description ?? event.type}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {event.actor_role ?? 'system'}
                                            {event.created_at
                                                ? ` · ${new Date(event.created_at).toLocaleString()}`
                                                : ''}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="text-muted-foreground">No events yet.</p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
