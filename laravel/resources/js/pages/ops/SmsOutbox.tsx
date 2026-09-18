import { Copy, MessageSquare } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { get } from '@/lib/api';
import type { AppNotification, DataResponse } from '@/lib/types';

const POLL_MS = 10000;

/**
 * Ops-visible mock SMS outbox: the recipient messages the system "sent", each
 * carrying a copyable magic link. No real SMS is delivered.
 */
export default function SmsOutbox() {
    const [messages, setMessages] = useState<AppNotification[]>([]);

    const load = useCallback(() => {
        get<DataResponse<AppNotification[]>>('/ops/sms-outbox')
            .then((response) => setMessages(response.data))
            .catch(() => {
                /* handled by api.ts */
            });
    }, []);

    useEffect(() => {
        load();
        const timer = setInterval(load, POLL_MS);
        return () => clearInterval(timer);
    }, [load]);

    const copy = async (link: string) => {
        try {
            await navigator.clipboard.writeText(link);
            toast.success('Link copied.');
        } catch {
            toast.error('Could not copy the link.');
        }
    };

    return (
        <div className="grid gap-6">
            <div className="flex items-center gap-3">
                <MessageSquare className="size-5 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">SMS outbox</h1>
            </div>
            <p className="text-sm text-muted-foreground">
                Mock recipient messages. These are not delivered; each carries the recipient's tracking
                link.
            </p>

            <Card>
                <CardHeader>
                    <CardTitle>Messages</CardTitle>
                </CardHeader>
                <CardContent>
                    {messages.length === 0 ? (
                        <p className="text-muted-foreground">No messages yet.</p>
                    ) : (
                        <ScrollArea className="max-h-[32rem]">
                            <ul className="grid gap-3">
                                {messages.map((message) => (
                                    <li key={message.id} className="rounded-lg border p-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="grid gap-1">
                                                <span className="text-sm">{message.message}</span>
                                                {message.job_id ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Job #{message.job_id}
                                                        {message.created_at
                                                            ? ` · ${new Date(message.created_at).toLocaleString()}`
                                                            : ''}
                                                    </span>
                                                ) : null}
                                            </div>
                                            {message.magic_link ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="shrink-0"
                                                    onClick={() => copy(message.magic_link as string)}
                                                >
                                                    <Copy className="size-3.5" />
                                                    Copy link
                                                </Button>
                                            ) : null}
                                        </div>
                                        {message.magic_link ? (
                                            <p className="mt-2 truncate text-xs text-muted-foreground">
                                                {message.magic_link}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </ScrollArea>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
