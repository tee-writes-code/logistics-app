import { HelpCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { AgentAskCard } from '@/components/AgentAskCard';
import { get } from '@/lib/api';
import type { AgentAsk } from '@/lib/types';

/** The customer's pending agent asks (e.g. reschedule / return confirmations). */
export default function AgentAsks() {
    const [asks, setAsks] = useState<AgentAsk[]>([]);

    const load = useCallback(() => {
        get<{ data: AgentAsk[] }>('/agent-asks')
            .then((r) => setAsks(r.data))
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <div className="grid gap-6">
            <div className="flex items-center gap-3">
                <HelpCircle className="size-5 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">Confirmations</h1>
            </div>
            <p className="text-sm text-muted-foreground">
                When a delivery can't complete as planned, we ask you to choose the next step.
            </p>

            <div className="grid gap-3">
                {asks.length === 0 ? (
                    <p className="text-muted-foreground">Nothing needs your confirmation right now.</p>
                ) : (
                    asks.map((ask) => <AgentAskCard key={ask.id} ask={ask} onResolved={load} />)
                )}
            </div>
        </div>
    );
}
