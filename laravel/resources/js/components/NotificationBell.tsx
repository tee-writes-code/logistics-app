import { Bell } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { get, post } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { AppNotification, DataResponse } from '@/lib/types';

const POLL_MS = 10000;

/**
 * Polled in-app notification inbox with per-item mark-read. Mounted in AppShell
 * for customer, rider, and ops. Mock SMS rows never appear here (the API returns
 * only in-app rows scoped to the current user).
 */
export function NotificationBell() {
    const [items, setItems] = useState<AppNotification[]>([]);
    const [open, setOpen] = useState(false);

    const load = useCallback(() => {
        get<DataResponse<AppNotification[]>>('/notifications')
            .then((response) => setItems(response.data))
            .catch(() => {
                /* transient errors and 401 are handled by api.ts */
            });
    }, []);

    useEffect(() => {
        load();
        const timer = setInterval(load, POLL_MS);
        return () => clearInterval(timer);
    }, [load]);

    const unread = items.filter((item) => item.read_at === null).length;

    const markRead = async (id: number) => {
        setItems((prev) =>
            prev.map((item) =>
                item.id === id ? { ...item, read_at: new Date().toISOString() } : item,
            ),
        );
        try {
            await post(`/notifications/${id}/read`);
        } catch {
            load();
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button variant="outline" size="icon" className="relative" aria-label="Notifications">
                        <Bell className="size-4" />
                        {unread > 0 ? (
                            <span className="absolute -top-1.5 -right-1.5 flex size-4.5 min-w-4.5 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground">
                                {unread > 9 ? '9+' : unread}
                            </span>
                        ) : null}
                    </Button>
                }
            />
            <PopoverContent align="end" className="w-80 p-0">
                <div className="px-3 py-2 text-sm font-medium">Notifications</div>
                <Separator />
                {items.length === 0 ? (
                    <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                        You're all caught up.
                    </p>
                ) : (
                    <ScrollArea className="max-h-80">
                        <ul className="divide-y">
                            {items.map((item) => (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        onClick={() => markRead(item.id)}
                                        className={cn(
                                            'flex w-full flex-col items-start gap-1 px-3 py-2.5 text-left transition-colors hover:bg-accent',
                                            item.read_at === null && 'bg-accent/40',
                                        )}
                                    >
                                        <span className="flex w-full items-start gap-2">
                                            {item.read_at === null ? (
                                                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" />
                                            ) : (
                                                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-transparent" />
                                            )}
                                            <span className="text-sm">{item.message}</span>
                                        </span>
                                        {item.created_at ? (
                                            <span className="pl-4 text-xs text-muted-foreground">
                                                {new Date(item.created_at).toLocaleString()}
                                            </span>
                                        ) : null}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </ScrollArea>
                )}
            </PopoverContent>
        </Popover>
    );
}
