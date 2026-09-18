import { Bot } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { AgentAskCard } from '@/components/AgentAskCard';
import { AgentPane } from '@/components/AgentPane';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { get } from '@/lib/api';
import type { AgentAsk, AgentLog, AgentPane as AgentPaneData } from '@/lib/types';

/** The Agent workbench: per-agent panes, pending asks, and the raw action log. */
export default function Workbench() {
    const [panes, setPanes] = useState<AgentPaneData[]>([]);
    const [asks, setAsks] = useState<AgentAsk[]>([]);
    const [logs, setLogs] = useState<AgentLog[]>([]);

    const load = useCallback(() => {
        get<{ data: AgentPaneData[] }>('/ops/workbench').then((r) => setPanes(r.data)).catch(() => undefined);
        get<{ data: AgentAsk[] }>('/agent-asks').then((r) => setAsks(r.data)).catch(() => undefined);
        get<{ data: AgentLog[] }>('/ops/agent-logs').then((r) => setLogs(r.data)).catch(() => undefined);
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <div className="grid gap-6">
            <div className="flex items-center gap-3">
                <Bot className="size-5 text-primary" />
                <h1 className="text-2xl font-semibold tracking-tight">Agent workbench</h1>
            </div>

            <Tabs defaultValue="panes">
                <TabsList>
                    <TabsTrigger value="panes">Agents</TabsTrigger>
                    <TabsTrigger value="asks">Pending asks ({asks.length})</TabsTrigger>
                    <TabsTrigger value="logs">Activity log</TabsTrigger>
                </TabsList>

                <TabsContent value="panes" className="mt-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        {panes.map((pane) => (
                            <AgentPane key={pane.agent} pane={pane} />
                        ))}
                    </div>
                </TabsContent>

                <TabsContent value="asks" className="mt-4">
                    <div className="grid gap-3">
                        {asks.length === 0 ? (
                            <p className="text-muted-foreground">No pending asks.</p>
                        ) : (
                            asks.map((ask) => (
                                <AgentAskCard key={ask.id} ask={ask} onResolved={load} />
                            ))
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="logs" className="mt-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent agent actions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ScrollArea className="max-h-[32rem]">
                                <ul className="grid gap-2 text-sm">
                                    {logs.map((log) => (
                                        <li key={log.id} className="rounded-md border p-2">
                                            <span className="font-medium">{log.agent}</span>
                                            {log.job_id ? ` · Job #${log.job_id}` : ''} —{' '}
                                            <span className="text-muted-foreground">{log.last_action}</span>
                                            {log.pending_ask ? (
                                                <span className="text-destructive"> · {log.pending_ask}</span>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            </ScrollArea>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </div>
    );
}
