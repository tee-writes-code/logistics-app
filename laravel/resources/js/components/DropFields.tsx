import { useFormContext } from 'react-hook-form';

import {
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

/**
 * Recipient / drop-off fields. Field names match the booking and edit forms:
 * drop_address, drop_contact_name, drop_phone. Use inside a react-hook-form
 * <Form> provider.
 */
export function DropFields() {
    const { control } = useFormContext();

    return (
        <div className="grid gap-4">
            <FormField
                control={control}
                name="drop_address"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Drop-off address</FormLabel>
                        <FormControl>
                            <Textarea rows={2} placeholder="Street, city" {...field} />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                    control={control}
                    name="drop_contact_name"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Recipient name</FormLabel>
                            <FormControl>
                                <Input placeholder="Full name" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                <FormField
                    control={control}
                    name="drop_phone"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Recipient phone</FormLabel>
                            <FormControl>
                                <Input placeholder="+1-555-…" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
            </div>
        </div>
    );
}
