<?php

declare(strict_types=1);

namespace App\Agents\Templates;

use App\Enums\JobStatus;
use App\Models\Job;

/**
 * Deterministic, canned copy for the Customer-ops agent. Every string is a fixed
 * template filled from job state — no LLM, no free text. Notification copy is
 * keyed by event + audience; the status line is keyed by current status and never
 * invents a location before the part is picked up.
 */
class CustomerOpsTemplates
{
    /**
     * Plain-language notification body for an event and audience.
     */
    public static function notification(string $event, string $audience, Job $job): string
    {
        $ref = 'Job #'.$job->id;

        return match ($event) {
            'assigned' => $audience === 'recipient'
                ? "Good news — your delivery ({$ref}) is booked and a rider is on the way. Track it with the link below."
                : "{$ref}: a rider has been assigned and is scheduled to collect the part.",
            'reassigned' => $audience === 'recipient'
                ? "Update on your delivery ({$ref}): we've assigned a new rider to keep it on schedule."
                : "{$ref}: reassigned to a new rider to keep the delivery on schedule.",
            'picked_up' => $audience === 'recipient'
                ? "Your part for {$ref} has been collected and is on its way to you."
                : "{$ref}: the part has been collected and is en route.",
            'on_site' => $audience === 'recipient'
                ? "Your rider has arrived for {$ref}. Please confirm receipt or refuse below."
                : "{$ref}: the rider is on site at the drop-off.",
            'delivered' => $audience === 'recipient'
                ? "Your delivery ({$ref}) is complete. Thank you."
                : "{$ref}: delivered with proof of delivery.",
            'failed' => $audience === 'recipient'
                ? "We couldn't complete the delivery for {$ref} this time. We're sorting out the next step."
                : "{$ref}: the delivery attempt failed; the exception agent is planning the next step.",
            'reattempt' => $audience === 'recipient'
                ? "We're making another attempt to deliver {$ref} today."
                : "{$ref}: a same-day reattempt is under way.",
            'next' => $audience === 'recipient'
                ? "Your delivery ({$ref}) has been rescheduled to the next available window."
                : "{$ref}: rescheduled to the next available window.",
            'return' => $audience === 'recipient'
                ? "The part for {$ref} is being returned to the shop."
                : "{$ref}: the part is being returned to the shop.",
            'returned' => $audience === 'recipient'
                ? "The part for {$ref} has been returned to the shop."
                : "{$ref}: the part has been returned to the shop.",
            'unsafe' => $audience === 'recipient'
                ? "Your delivery ({$ref}) is paused while we review a safety concern."
                : "{$ref}: paused pending an Ops safety review.",
            default => "{$ref} was updated ({$event}).",
        };
    }

    /**
     * The short timeline note posted on a plan change.
     */
    public static function planNote(string $event, Job $job): string
    {
        return match ($event) {
            'assigned' => 'Dispatch assigned a rider and scheduled the run.',
            'reassigned' => 'Reassigned to a new rider.',
            'reattempt' => 'Exception planned a same-day reattempt.',
            'next' => 'Rescheduled to the next available window.',
            'return' => 'Return to the shop initiated.',
            'unsafe' => 'Paused pending an Ops safety review.',
            default => 'Plan updated.',
        };
    }

    /**
     * A templated status line composed from the job's current state. Never claims
     * a live location before the part is picked up.
     */
    public static function status(Job $job): string
    {
        $ref = 'Job #'.$job->id;
        $eta = $job->eta_at !== null ? ' ETA '.$job->eta_at->toDayDateTimeString().'.' : '';

        return match ($job->status) {
            JobStatus::Booked => "{$ref} is booked and awaiting a rider.",
            JobStatus::Assigned => "{$ref} has a rider assigned and will be collected shortly.{$eta}",
            JobStatus::EnRoutePickup => "{$ref}: the rider is heading to the pickup.",
            JobStatus::AtPickup => "{$ref}: the rider is at the pickup collecting the part.",
            JobStatus::PickedUp => "{$ref}: the part has been collected and is on the move.{$eta}",
            JobStatus::EnRouteDrop => "{$ref}: the part is en route to the drop-off.{$eta}",
            JobStatus::OnSite => "{$ref}: the rider is on site at the drop-off.",
            JobStatus::Delivered => "{$ref} was delivered successfully.",
            JobStatus::Failed => "{$ref}: the last delivery attempt failed; we're planning the next step.",
            JobStatus::Returning => "{$ref}: the part is being returned to the shop.",
            JobStatus::Returned => "{$ref}: the part has been returned to the shop.",
            JobStatus::Cancelled => "{$ref} was cancelled.",
        };
    }
}
