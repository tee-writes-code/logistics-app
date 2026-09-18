import { useFormContext } from 'react-hook-form';

import {
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';

/**
 * The single part line for a job. Field names: part_line.name, part_line.sku,
 * part_line.qty, part_line.serial. Use inside a react-hook-form <Form> provider.
 */
export function PartLineFields() {
    const { control } = useFormContext();

    return (
        <div className="grid gap-4">
            <FormField
                control={control}
                name="part_line.name"
                render={({ field }) => (
                    <FormItem>
                        <FormLabel>Part name</FormLabel>
                        <FormControl>
                            <Input placeholder="e.g. Alternator" {...field} />
                        </FormControl>
                        <FormMessage />
                    </FormItem>
                )}
            />
            <div className="grid gap-4 sm:grid-cols-3">
                <FormField
                    control={control}
                    name="part_line.sku"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>SKU</FormLabel>
                            <FormControl>
                                <Input placeholder="Optional" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                <FormField
                    control={control}
                    name="part_line.qty"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Quantity</FormLabel>
                            <FormControl>
                                <Input type="number" min={1} {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                <FormField
                    control={control}
                    name="part_line.serial"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Serial</FormLabel>
                            <FormControl>
                                <Input placeholder="Optional" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
            </div>
        </div>
    );
}
