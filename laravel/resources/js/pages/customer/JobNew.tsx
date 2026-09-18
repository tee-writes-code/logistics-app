import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { z } from 'zod';

import { DropFields } from '@/components/DropFields';
import { OfferNextDialog } from '@/components/OfferNextDialog';
import { PartLineFields } from '@/components/PartLineFields';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Form,
    FormControl,
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, get, post } from '@/lib/api';
import type { DataResponse, Job, OfferNextResponse, PickupSite } from '@/lib/types';

const schema = z.object({
    pickup_site_id: z.string().min(1, 'Choose a pickup site.'),
    drop_address: z.string().min(1, 'Drop-off address is required.'),
    drop_contact_name: z.string().min(1, 'Recipient name is required.'),
    drop_phone: z.string().min(1, 'Recipient phone is required.'),
    part_line: z.object({
        name: z.string().min(1, 'Part name is required.'),
        sku: z.string().optional(),
        qty: z.coerce.number().int().min(1, 'Quantity must be at least 1.'),
        serial: z.string().optional(),
    }),
    window: z.enum(['same_day', 'next']),
    notes: z.string().optional(),
    save_drop: z.boolean().optional(),
    save_drop_label: z.string().optional(),
});

type BookValues = z.input<typeof schema>;

// Base UI's Select resolves the trigger's display text from `items`, not from
// the rendered SelectItem children. This record mirrors the window option text
// so the trigger shows "Same day" instead of the raw "same_day" value.
const WINDOW_ITEMS: Record<string, string> = {
    same_day: 'Same day',
    next: 'Next window',
};

interface BookPayload {
    pickup_site_id: number;
    drop_address: string;
    drop_contact_name: string;
    drop_phone: string;
    part_line: { name: string; sku?: string; qty: number; serial?: string };
    window: 'same_day' | 'next';
    notes?: string;
    save_drop?: boolean;
    save_drop_label?: string;
    offer_next_accepted?: boolean;
}

function isOfferNext(value: unknown): value is OfferNextResponse {
    return typeof value === 'object' && value !== null && 'offer_next' in value;
}

export default function JobNew() {
    const [sites, setSites] = useState<PickupSite[]>([]);
    const [pendingPayload, setPendingPayload] = useState<BookPayload | null>(null);
    const [confirmingNext, setConfirmingNext] = useState(false);
    const navigate = useNavigate();

    const form = useForm<BookValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            pickup_site_id: '',
            drop_address: '',
            drop_contact_name: '',
            drop_phone: '',
            part_line: { name: '', sku: '', qty: 1, serial: '' },
            window: 'same_day',
            notes: '',
            save_drop: false,
            save_drop_label: '',
        },
    });

    useEffect(() => {
        get<DataResponse<PickupSite[]>>('/pickup-sites')
            .then((response) => setSites(response.data))
            .catch(() => toast.error('Could not load pickup sites.'));
    }, []);

    // Resolves true only when the job was booked (and we navigated away); false on
    // an offer-next branch or any error, so callers can decide whether to close UI.
    const submit = async (payload: BookPayload): Promise<boolean> => {
        try {
            const response = await post<DataResponse<Job> | OfferNextResponse>('/jobs', payload);
            if (isOfferNext(response)) {
                setPendingPayload(payload);
                return false;
            }
            toast.success('Job booked.');
            navigate(`/jobs/${response.data.id}`);
            return true;
        } catch (error) {
            if (error instanceof ApiError && error.errors) {
                for (const [field, messages] of Object.entries(error.errors)) {
                    form.setError(field as keyof BookValues, { message: messages[0] });
                }
            } else {
                toast.error(error instanceof ApiError ? error.message : 'Could not book the job.');
            }
            return false;
        }
    };

    const onSubmit = (values: BookValues) => {
        const parsed = schema.parse(values);
        return submit({
            pickup_site_id: Number(parsed.pickup_site_id),
            drop_address: parsed.drop_address,
            drop_contact_name: parsed.drop_contact_name,
            drop_phone: parsed.drop_phone,
            part_line: {
                name: parsed.part_line.name,
                sku: parsed.part_line.sku || undefined,
                qty: parsed.part_line.qty,
                serial: parsed.part_line.serial || undefined,
            },
            window: parsed.window,
            notes: parsed.notes || undefined,
            save_drop: parsed.save_drop,
            save_drop_label: parsed.save_drop_label || undefined,
        });
    };

    const confirmNext = async () => {
        if (!pendingPayload || confirmingNext) {
            return;
        }
        const payload = { ...pendingPayload, window: 'next' as const, offer_next_accepted: true };
        setConfirmingNext(true);
        try {
            const booked = await submit(payload);
            // Close the dialog only when the re-submit succeeded (submit navigates
            // away on success). On failure keep it open so the user can retry.
            if (booked) {
                setPendingPayload(null);
            }
        } finally {
            setConfirmingNext(false);
        }
    };

    const saveDrop = form.watch('save_drop');

    // Maps each site id to its label so the pickup Select trigger shows the site
    // name after a pick rather than the numeric id (Base UI reads this record).
    const siteItems: Record<string, string> = Object.fromEntries(
        sites.map((site) => [String(site.id), site.label]),
    );

    return (
        <div className="mx-auto grid max-w-2xl gap-6">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">Book a job</h1>
                <p className="text-muted-foreground">Enter pickup, drop-off, and part details.</p>
            </div>

            <Form {...form}>
                <form className="grid gap-6" onSubmit={form.handleSubmit(onSubmit)}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Pickup</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <FormField
                                control={form.control}
                                name="pickup_site_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Pickup site</FormLabel>
                                        <Select
                                            value={field.value}
                                            onValueChange={field.onChange}
                                            items={siteItems}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Choose a pickup site" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {sites.map((site) => (
                                                    <SelectItem
                                                        key={site.id}
                                                        value={String(site.id)}
                                                    >
                                                        {site.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Drop-off</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <DropFields />
                            <FormField
                                control={form.control}
                                name="save_drop"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center gap-2">
                                        <FormControl>
                                            <input
                                                type="checkbox"
                                                className="size-4 rounded border-input"
                                                checked={field.value ?? false}
                                                onChange={(event) =>
                                                    field.onChange(event.target.checked)
                                                }
                                            />
                                        </FormControl>
                                        <FormLabel className="font-normal">
                                            Save this drop-off for reuse
                                        </FormLabel>
                                    </FormItem>
                                )}
                            />
                            {saveDrop ? (
                                <FormField
                                    control={form.control}
                                    name="save_drop_label"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Saved drop label</FormLabel>
                                            <FormControl>
                                                <Input placeholder="e.g. Client A" {...field} />
                                            </FormControl>
                                            <FormDescription>
                                                Reusing an existing label updates that saved drop.
                                            </FormDescription>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Part</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <PartLineFields />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Delivery window</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <FormField
                                control={form.control}
                                name="window"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Window</FormLabel>
                                        <Select
                                            value={field.value}
                                            onValueChange={field.onChange}
                                            items={WINDOW_ITEMS}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="same_day">Same day</SelectItem>
                                                <SelectItem value="next">Next window</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="notes"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Notes (optional)</FormLabel>
                                        <FormControl>
                                            <Textarea rows={2} {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => navigate('/jobs')}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.formState.isSubmitting}>
                            {form.formState.isSubmitting ? 'Booking…' : 'Book job'}
                        </Button>
                    </div>
                </form>
            </Form>

            <OfferNextDialog
                open={pendingPayload !== null}
                onConfirm={confirmNext}
                onCancel={() => {
                    if (!confirmingNext) {
                        setPendingPayload(null);
                    }
                }}
                pending={confirmingNext}
            />
        </div>
    );
}
