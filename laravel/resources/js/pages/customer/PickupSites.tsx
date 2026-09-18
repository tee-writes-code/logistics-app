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
import type { DataResponse, PickupSite } from '@/lib/types';

const schema = z.object({
    label: z.string().min(1, 'Label is required.'),
    address: z.string().min(1, 'Address is required.'),
    contact_name: z.string().optional(),
    contact_phone: z.string().optional(),
});

type SiteValues = z.infer<typeof schema>;

export default function PickupSites() {
    const [sites, setSites] = useState<PickupSite[]>([]);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<PickupSite | null>(null);

    const form = useForm<SiteValues>({
        resolver: zodResolver(schema),
        defaultValues: { label: '', address: '', contact_name: '', contact_phone: '' },
    });

    const load = () => {
        get<DataResponse<PickupSite[]>>('/pickup-sites')
            .then((response) => setSites(response.data))
            .catch(() => toast.error('Could not load pickup sites.'));
    };

    useEffect(load, []);

    const openCreate = () => {
        setEditing(null);
        form.reset({ label: '', address: '', contact_name: '', contact_phone: '' });
        setDialogOpen(true);
    };

    const openEdit = (site: PickupSite) => {
        setEditing(site);
        form.reset({
            label: site.label,
            address: site.address,
            contact_name: site.contact_name ?? '',
            contact_phone: site.contact_phone ?? '',
        });
        setDialogOpen(true);
    };

    const onSubmit = async (values: SiteValues) => {
        try {
            if (editing) {
                await put(`/pickup-sites/${editing.id}`, values);
                toast.success('Pickup site updated.');
            } else {
                await post('/pickup-sites', values);
                toast.success('Pickup site added.');
            }
            setDialogOpen(false);
            load();
        } catch (error) {
            if (error instanceof ApiError && error.errors) {
                for (const [field, messages] of Object.entries(error.errors)) {
                    form.setError(field as keyof SiteValues, { message: messages[0] });
                }
            } else {
                toast.error(error instanceof ApiError ? error.message : 'Could not save.');
            }
        }
    };

    const remove = async (site: PickupSite) => {
        try {
            await del(`/pickup-sites/${site.id}`);
            toast.success('Pickup site deleted.');
            load();
        } catch (error) {
            toast.error(error instanceof ApiError ? error.message : 'Could not delete.');
        }
    };

    return (
        <div className="grid gap-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Pickup sites</h1>
                    <p className="text-muted-foreground">Saved locations you collect parts from.</p>
                </div>
                <Button onClick={openCreate}>
                    <Plus className="size-4" />
                    Add site
                </Button>
            </div>

            {sites.length === 0 ? (
                <p className="text-muted-foreground">No pickup sites yet.</p>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    {sites.map((site) => (
                        <Card key={site.id}>
                            <CardHeader>
                                <CardTitle>{site.label}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                <div className="text-sm text-muted-foreground">{site.address}</div>
                                <div className="text-sm">
                                    {site.contact_name || '—'}
                                    {site.contact_phone ? ` · ${site.contact_phone}` : ''}
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => openEdit(site)}
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
                                                    Delete this pickup site?
                                                </AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Sites used by an open job cannot be deleted.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>Keep</AlertDialogCancel>
                                                <AlertDialogAction onClick={() => remove(site)}>
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
                        <DialogTitle>{editing ? 'Edit pickup site' : 'Add pickup site'}</DialogTitle>
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
                                name="contact_phone"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Contact phone</FormLabel>
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
