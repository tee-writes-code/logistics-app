/** Shared API response shapes. Mirror the backend API Resources. */

export type UserRole = 'customer' | 'rider' | 'ops';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: UserRole;
}

export type JobWindow = 'same_day' | 'next';

export type JobStatus =
    | 'booked'
    | 'assigned'
    | 'en_route_pickup'
    | 'at_pickup'
    | 'picked_up'
    | 'en_route_drop'
    | 'on_site'
    | 'delivered'
    | 'failed'
    | 'returning'
    | 'returned'
    | 'cancelled';

export interface PickupSite {
    id: number;
    label: string;
    address: string;
    contact_name: string | null;
    contact_phone: string | null;
}

export interface SavedDrop {
    id: number;
    label: string;
    address: string;
    contact_name: string | null;
    phone: string | null;
}

export interface PartLine {
    id: number;
    name: string;
    sku: string | null;
    quantity: number;
    serial: string | null;
}

export interface TimelineEvent {
    id: number;
    type: string;
    actor_role: string | null;
    agent: string | null;
    description: string | null;
    meta: Record<string, unknown> | null;
    created_at: string | null;
}

export type FailReason = 'closed' | 'wrong_site' | 'no_contact';

export interface RiderRef {
    id: number;
    name: string;
}

export interface JobPendingAsk {
    id: number;
    type: AgentAskType;
    agent: AgentName;
}

export interface Job {
    id: number;
    status: JobStatus;
    window: JobWindow;
    assigned_rider_id: number | null;
    queue_position: number | null;
    is_active_for_rider: boolean;
    is_terminal: boolean;
    has_pod?: boolean;
    fail_reason?: FailReason | null;
    pickup_site_id: number | null;
    customer_id?: number;
    drop_address: string;
    drop_contact_name: string;
    drop_contact_phone: string;
    notes: string | null;
    instructions: string | null;
    eta_at: string | null;
    booked_at: string | null;
    assigned_at: string | null;
    picked_up_at: string | null;
    delivered_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;
    can_edit_drop: boolean;
    can_edit_notes: boolean;
    can_cancel: boolean;
    assigned_rider?: RiderRef | null;
    customer?: RiderRef | null;
    pending_asks?: JobPendingAsk[];
    pickup_site?: PickupSite;
    part_line?: PartLine;
    timeline?: TimelineEvent[];
}

/** Deterministic agents. */
export type AgentName = 'dispatch' | 'exception' | 'customer_ops';
export type AgentAskType = 'next' | 'return' | 'unsafe';
export type AgentAskStatus = 'pending' | 'confirmed' | 'rejected';

export interface AgentAsk {
    id: number;
    agent: AgentName;
    type: AgentAskType;
    status: AgentAskStatus;
    payload: Record<string, unknown> | null;
    job_id: number;
    job: {
        id: number;
        status: JobStatus;
        drop_address: string;
        customer_id: number | null;
    } | null;
    resolved_by: number | null;
    resolved_at: string | null;
    created_at: string | null;
}

export interface AgentPane {
    agent: AgentName;
    goal: string | null;
    last_action: string | null;
    pending_ask: string | null;
    pending_ask_count: number;
    updated_at: string | null;
    job_links: Array<{
        log_id: number;
        job_id: number | null;
        last_action: string | null;
        created_at: string | null;
    }>;
}

export interface AgentLog {
    id: number;
    job_id: number | null;
    agent: AgentName;
    goal: string | null;
    last_action: string | null;
    pending_ask: string | null;
    meta: Record<string, unknown> | null;
    created_at: string | null;
}

export interface RiderBoardEntry {
    id: number;
    name: string;
    email: string;
    load: number;
    queue: Job[];
}

/** A mock live-map position (unit-square coordinates), or null out of window. */
export interface MapPoint {
    x: number;
    y: number;
}

export interface JobPosition {
    pickup: MapPoint;
    drop: MapPoint;
    current: MapPoint;
    progress: number;
    status: JobStatus;
}

/** In-app notification / mock SMS row. */
export type NotificationChannel = 'in_app' | 'sms';

export interface AppNotification {
    id: number;
    job_id: number | null;
    role: UserRole | null;
    channel: NotificationChannel;
    message: string;
    magic_link: string | null;
    read_at: string | null;
    created_at: string | null;
}

/** The recipient magic-link view of a single job. */
export interface RecipientView {
    valid: boolean;
    token?: string;
    job?: {
        id: number;
        status: JobStatus;
        is_terminal: boolean;
        eta_at: string | null;
        picked_up_at: string | null;
        drop_address: string;
        drop_contact_name: string;
        part_line: {
            name: string;
            sku: string | null;
            quantity: number;
            serial: string | null;
        } | null;
    };
    can?: {
        instruct: boolean;
        hold: boolean;
        receive_confirm: boolean;
        refuse: boolean;
    };
    hold?: {
        active: boolean;
        flag_miss_hours: boolean;
    };
    instruction?: string | null;
    map_eligible?: boolean;
    position?: JobPosition | null;
}

/** Envelope for a single resource. */
export interface DataResponse<T> {
    data: T;
}

/** The store-job endpoint returns this instead of a job when same-day cannot fit. */
export interface OfferNextResponse {
    offer_next: true;
}
