import { Badge } from '@/components/ui/badge';
import type { JobStatus } from '@/lib/types';

const LABELS: Record<JobStatus, string> = {
    booked: 'Booked',
    assigned: 'Assigned',
    en_route_pickup: 'En route to pickup',
    at_pickup: 'At pickup',
    picked_up: 'Picked up',
    en_route_drop: 'En route to drop',
    on_site: 'On site',
    delivered: 'Delivered',
    failed: 'Failed',
    returning: 'Returning',
    returned: 'Returned',
    cancelled: 'Cancelled',
};

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const VARIANTS: Record<JobStatus, BadgeVariant> = {
    booked: 'secondary',
    assigned: 'secondary',
    en_route_pickup: 'default',
    at_pickup: 'default',
    picked_up: 'default',
    en_route_drop: 'default',
    on_site: 'default',
    delivered: 'outline',
    failed: 'destructive',
    returning: 'default',
    returned: 'outline',
    cancelled: 'destructive',
};

export function JobStatusBadge({ status }: { status: JobStatus }) {
    return <Badge variant={VARIANTS[status]}>{LABELS[status]}</Badge>;
}
