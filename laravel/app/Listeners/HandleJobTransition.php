<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Agents\CustomerOpsAgent;
use App\Enums\JobStatus;
use App\Events\JobTransitioned;
use App\Services\MagicLinkService;

/**
 * The iter-3 side of the JobTransitioned seam: it issues/expires the recipient
 * magic link after a status change commits, and routes the customer-facing copy
 * for notified events through the Customer-ops agent.
 *
 * Notifications are emitted here EXACTLY ONCE per event by delegating to
 * CustomerOpsAgent::notify (which owns the templated copy and the single
 * NotificationService emit call). No other path emits for these events, so there
 * is no double-notify. Reattempt/reassign/return/unsafe are NOT in the notified
 * set below; the agents emit those directly, again exactly once.
 */
class HandleJobTransition
{
    /**
     * Events whose notification set the Customer-ops agent composes. Absent
     * events (e.g. en_route_pickup) notify no one.
     *
     * @var array<int, string>
     */
    private const NOTIFIED_EVENTS = [
        'assigned', 'picked_up', 'on_site', 'delivered', 'failed', 'returned',
    ];

    public function __construct(
        private readonly CustomerOpsAgent $customerOps,
        private readonly MagicLinkService $magicLinks,
    ) {}

    public function handle(JobTransitioned $event): void
    {
        // A silent transition is an internal status correction (a delay reset back
        // to `assigned`) whose customer-facing plan-change notice is emitted by the
        // driving agent instead. It issues no notification and rotates no magic link
        // (the recipient's existing token stays valid across the reassignment).
        if ($event->payload['silent'] ?? false) {
            return;
        }

        $job = $event->job;
        $to = $event->to;

        // A job reaching `assigned` gets a fresh recipient magic link before the
        // SMS notification is composed, so the SMS carries the new link.
        if ($to === JobStatus::Assigned) {
            $this->magicLinks->issueFor($job);
            $job->refresh();
        }

        // Single notification path: the Customer-ops agent composes agent-authored
        // copy and calls NotificationService::emit exactly once for this event.
        if (in_array($to->value, self::NOTIFIED_EVENTS, true)) {
            $this->customerOps->notify($job, $to->value);
        }

        // Terminal states end the recipient session (link becomes read-only).
        // Runs after emit so a delivered/returned SMS still carries the link.
        if ($to->isTerminal()) {
            $this->magicLinks->expire($job);
        }
    }
}
