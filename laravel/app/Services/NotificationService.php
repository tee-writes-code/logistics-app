<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Job;

/**
 * Writes the app's notification rows: in-app entries for the Customer/Rider/Ops
 * audiences and a mock SMS entry (carrying the recipient magic link) for the
 * Recipient. This is the single notification writer.
 *
 * iter-3 drives it from the JobTransitioned listener with system-generated copy.
 * iter-4's CustomerOps agent will call emit() with its own audiences and richer,
 * templated copy; the plain-language composition lives in compose() so it can be
 * swapped without touching the emit/persist path.
 */
class NotificationService
{
    public function __construct(private readonly MagicLinkService $magicLinks) {}

    /**
     * Emit one notification per audience for a job event.
     *
     * Audiences: 'customer', 'rider', 'ops' (in-app), 'recipient' (mock SMS).
     * A 'rider' audience is skipped when no rider is assigned.
     *
     * When $copy is given, it composes the body (agent-authored, plan-aware copy)
     * instead of the built-in compose(); this is how iter-4's CustomerOps agent
     * supplies its own templates without changing the default iter-3 behavior.
     * The resolver signature is fn(string $event, string $audience, Job $job).
     *
     * @param  array<int, string>  $audiences
     * @param  (callable(string, string, Job): string)|null  $copy
     */
    public function emit(Job $job, string $event, array $audiences, ?callable $copy = null): void
    {
        foreach ($audiences as $audience) {
            match ($audience) {
                'customer' => $this->inApp($job, $event, UserRole::Customer, $job->customer_id, $copy),
                'rider' => $job->assigned_rider_id !== null
                    ? $this->inApp($job, $event, UserRole::Rider, $job->assigned_rider_id, $copy)
                    : null,
                'ops' => $this->inApp($job, $event, UserRole::Ops, null, $copy),
                'recipient' => $this->sms($job, $event, $copy),
                default => null,
            };
        }
    }

    /**
     * @param  (callable(string, string, Job): string)|null  $copy
     */
    private function inApp(Job $job, string $event, UserRole $role, ?int $userId, ?callable $copy = null): void
    {
        $job->notifications()->create([
            'user_id' => $userId,
            'role' => $role,
            'channel' => NotificationChannel::InApp,
            'message' => $this->body($event, $role->value, $job, $copy),
        ]);
    }

    /**
     * @param  (callable(string, string, Job): string)|null  $copy
     */
    private function sms(Job $job, string $event, ?callable $copy = null): void
    {
        $session = $job->magicLinkSession()->latest('id')->first();

        $job->notifications()->create([
            'user_id' => null,
            'role' => null,
            'channel' => NotificationChannel::Sms,
            'message' => $this->body($event, 'recipient', $job, $copy),
            'magic_link' => $session !== null ? $this->magicLinks->trackUrlFor($session) : null,
        ]);
    }

    /**
     * Resolve the notification body: agent-authored copy when supplied, else the
     * built-in system copy.
     *
     * @param  (callable(string, string, Job): string)|null  $copy
     */
    private function body(string $event, string $audience, Job $job, ?callable $copy): string
    {
        return $copy !== null ? $copy($event, $audience, $job) : $this->compose($event, $audience, $job);
    }

    /**
     * Compose the plain-language notification body for an event + audience.
     *
     * System-generated for now. iter-4 replaces this method with agent-authored,
     * plan-aware copy; the emit path above stays unchanged.
     */
    protected function compose(string $event, string $audience, Job $job): string
    {
        $ref = 'Job #'.$job->id;

        return match ($event) {
            'assigned' => $audience === 'recipient'
                ? "Your delivery ({$ref}) is booked and a rider is assigned. Track it with the link below."
                : "{$ref} has been assigned to a rider.",
            'picked_up' => $audience === 'recipient'
                ? "Your part for {$ref} has been collected and is on the way."
                : "{$ref} has been picked up.",
            'on_site' => $audience === 'recipient'
                ? "Your rider has arrived for {$ref}. Please confirm receipt or refuse below."
                : "The rider is on site for {$ref}.",
            'delivered' => $audience === 'recipient'
                ? "Your delivery ({$ref}) is complete. Thank you."
                : "{$ref} has been delivered.",
            'failed' => $audience === 'recipient'
                ? "The delivery attempt for {$ref} was not completed."
                : "The delivery attempt for {$ref} failed.",
            'returned' => $audience === 'recipient'
                ? "The part for {$ref} has been returned to the shop."
                : "{$ref} has been returned to the shop.",
            default => "{$ref} was updated ({$event}).",
        };
    }
}
