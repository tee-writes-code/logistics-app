import { Link } from 'react-router-dom';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { AgentPane as AgentPaneData } from '@/lib/types';

const AGENT_LABEL: Record<AgentPaneData['agent'], string> = {
    dispatch: 'Dispatch',
    exception: 'Exception',
    customer_ops: 'Customer-ops',
};

/**
 * One agent's workbench pane: its current goal, last action, any pending ask, and
 * recent job links.
 */
export function AgentPane({ pane }: { pane: AgentPaneData }) {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle>{AGENT_LABEL[pane.agent]}</CardTitle>
                    {pane.pending_ask_count > 0 ? (
                        <Badge variant="destructive">{pane.pending_ask_count} pending</Badge>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                <div>
                    <p className="font-medium">Goal</p>
                    <p className="text-muted-foreground">{pane.goal ?? '—'}</p>
                </div>
                <div>
                    <p className="font-medium">Last action</p>
                    <p className="text-muted-foreground">{pane.last_action ?? '—'}</p>
                </div>
                <div>
                    <p className="font-medium">Pending ask</p>
                    <p className="text-muted-foreground">{pane.pending_ask ?? 'None'}</p>
                </div>
                {pane.job_links.length > 0 ? (
                    <div>
                        <p className="font-medium">Recent jobs</p>
                        <ul className="mt-1 grid gap-1">
                            {pane.job_links.map((link) => (
                                <li key={link.log_id} className="text-muted-foreground">
                                    {link.job_id !== null ? (
                                        <Link
                                            className="text-primary underline underline-offset-4"
                                            to={`/ops/jobs/${link.job_id}`}
                                        >
                                            Job #{link.job_id}
                                        </Link>
                                    ) : (
                                        <span>—</span>
                                    )}
                                    {link.last_action ? ` · ${link.last_action}` : ''}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
