import { zodResolver } from '@hookform/resolvers/zod';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';

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
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { ApiError, del, get, post, put } from '@/lib/api';
import type { DataResponse, SavedDrop } from '@/lib/types';

const schema = z.object({
    label: z.string().min(1, 'Label is required.'),
    address: z.string().min(1, 'Address is required.'),
    contact_name: z.string().optional(),
    phone: z.string().optional(),
});

type DropValues = z.infer<typeof schema>;

export default function SavedDrops() {
    const [drops, setDrops] = useState<SavedDrop[]>([]);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<SavedDrop | null>(null);

    const form = useForm<DropValues>({
        resolver: zodResolver(schema),
        defaultValues: { label: '', address: '', contact_name: '', phone: '' },
    });

    const load = () => {
        get<DataResponse<SavedDrop[]>>('/saved-drops')
            .then((response) => setDrops(response.data))
            .catch(() => toast.error('Could not load saved drops.'));
    };

    useEffect(load, []);

    const openCreate = () => {
        setEditing(null);
        form.reset({ label: '', address: '', contact_name: '', phone: '' });
        setDialogOpen(true);
    };

    const openEdit = (drop: SavedDrop) => {
        setEditing(drop);
        form.reset({
            label: drop.label,
            address: drop.address,
            contact_name: drop.contact_name ?? '',
            phone: drop.phone ?? '',
        });
        setDialogOpen(true);
    };

    const onSubmit = async (values: DropValues) => {
        try {
            if (editing) {
                await put(`/saved-drops/${editing.id}`, values);
                toast.success('Saved drop updated.');
            } else {
                await post('/saved-drops', values);
                toast.success('Saved drop added.');
            }
            setDialogOpen(false);
            load();
        } catch (error) {
            if (error instanceof ApiError && error.errors) {
                for (const [field, messages] of Object.entries(error.errors)) {
                    form.setError(field as keyof DropValues, { message: messages[0] });
                }
            } else {
                toast.error(error instanceof ApiError ? error.message : 'Could not save.');
            }
        }
    };

    const remove = async (drop: SavedDrop) => {
        try {
            await del(`/saved-drops/${drop.id}`);
            toast.success('Saved drop deleted.');
            load();
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not delete.');
        }
    };

    return (
        <div className="grid gap-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Saved drops</h1>
                    <p className="text-muted-foreground">Reusable drop-off recipients.</p>
                </div>
                <Button onClick={openCreate}>
                    <Plus className="size-4" />
                    Add drop
                </Button>
            </div>

            {drops.length === 0 ? (
                <p className="text-muted-foreground">No saved drops yet.</p>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    {drops.map((drop) => (
                        <Card key={drop.id}>
                            <CardHeader>
                                <CardTitle>{drop.label}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                <div className="text-sm text-muted-foreground">{drop.address}</div>
                                <div className="text-sm">
                                    {drop.contact_name || '—'}
                                    {drop.phone ? ` · ${drop.phone}` : ''}
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => openEdit(drop)}
                                    >
                                        Edit
                                    </Button>
                                    <AlertDialog>
                                        <AlertDialogTrigger
                                            render={
                                                <Button variant="ghost" size="sm">
                                                    Delete
                                                </Button>
                                            }
                                        />
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>
                                                    Delete this saved drop?
                                                </AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    This only removes the saved recipient, not any
                                                    booked jobs.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>Keep</AlertDialogCancel>
                                                <AlertDialogAction onClick={() => remove(drop)}>
                                                    Delete
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit saved drop' : 'Add saved drop'}</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form className="grid gap-4" onSubmit={form.handleSubmit(onSubmit)}>
                            <FormField
                                control={form.control}
                                name="label"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Label</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="address"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Address</FormLabel>
                                        <FormControl>
                                            <Textarea rows={2} {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="contact_name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Contact name</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="phone"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Phone</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <DialogFooter>
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Save
                                </Button>
                            </DialogFooter>
                        </form>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
