import * as React from 'react';
import { ToggleGroup as ToggleGroupPrimitive } from '@base-ui/react/toggle-group';
import { Toggle as TogglePrimitive } from '@base-ui/react/toggle';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const toggleVariants = cva(
    "inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors outline-none hover:bg-muted hover:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 data-[pressed]:bg-accent data-[pressed]:text-accent-foreground [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    {
        variants: {
            variant: {
                default: 'bg-transparent',
                outline:
                    'border border-input bg-transparent shadow-xs hover:bg-accent hover:text-accent-foreground',
            },
            size: {
                default: 'h-9 px-2 min-w-9',
                sm: 'h-8 px-1.5 min-w-8',
                lg: 'h-10 px-2.5 min-w-10',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

const ToggleGroupContext = React.createContext<VariantProps<typeof toggleVariants>>({
    size: 'default',
    variant: 'default',
});

function ToggleGroup({
    className,
    variant,
    size,
    children,
    ...props
}: ToggleGroupPrimitive.Props & VariantProps<typeof toggleVariants>) {
    return (
        <ToggleGroupPrimitive
            data-slot="toggle-group"
            data-variant={variant}
            data-size={size}
            className={cn(
                'group/toggle-group flex w-fit items-center rounded-md data-[variant=outline]:shadow-xs',
                className,
            )}
            {...props}
        >
            <ToggleGroupContext.Provider value={{ variant, size }}>
                {children}
            </ToggleGroupContext.Provider>
        </ToggleGroupPrimitive>
    );
}

function ToggleGroupItem({
    className,
    children,
    variant,
    size,
    ...props
}: TogglePrimitive.Props & VariantProps<typeof toggleVariants>) {
    const context = React.useContext(ToggleGroupContext);

    return (
        <TogglePrimitive
            data-slot="toggle-group-item"
            data-variant={context.variant ?? variant}
            data-size={context.size ?? size}
            className={cn(
                toggleVariants({ variant: context.variant ?? variant, size: context.size ?? size }),
                'min-w-0 flex-1 shrink-0 rounded-none shadow-none first:rounded-l-md last:rounded-r-md focus:z-10 focus-visible:z-10 data-[variant=outline]:border-l-0 data-[variant=outline]:first:border-l',
                className,
            )}
            {...props}
        >
            {children}
        </TogglePrimitive>
    );
}

export { ToggleGroup, ToggleGroupItem, toggleVariants };
