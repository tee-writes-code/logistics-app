<?php

namespace Database\Seeders;

use App\Enums\AgentType;
use App\Enums\FailReason;
use App\Enums\JobStatus;
use App\Enums\JobWindow;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Job;
use App\Models\PickupSite;
use App\Models\User;
use App\Services\MagicLinkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LogisticsDemoSeeder extends Seeder
{
    /**
     * Seed sample jobs spanning every status in the lifecycle, each with a
     * part line, drop details, and timeline events. Adds PODs, notifications,
     * an active recipient magic-link session, and agent action logs.
     *
     * Depends on RoleSeeder. The whole seed runs in a transaction, so a partial
     * failure rolls back cleanly and the guard (the demo customer already having
     * jobs) stays reliable. Every outcome write is scoped to the exact jobs this
     * seeder creates — never a global Job query.
     */
    public function run(MagicLinkService $magicLinks): void
    {
        $customer = User::where('email', 'customer@logistics.test')->firstOrFail();

        if ($customer->jobs()->exists()) {
            return;
        }

        $pickupSite = $customer->pickupSites()->firstOrFail();
        $riders = User::query()->where('role', UserRole::Rider)->orderBy('id')->get();

        DB::transaction(function () use ($customer, $pickupSite, $riders, $magicLinks): void {
            $jobs = $this->createJobs($customer, $pickupSite, $riders);
            $this->attachOutcomes($jobs, $magicLinks);
            $this->seedAgentLogs($jobs, $riders);
        });
    }

    /**
     * Create one job per lifecycle status, each with a part line and timeline
     * events. Returns the created jobs keyed by their status value.
     *
     * @param  Collection<int, User>  $riders
     * @return Collection<string, Job>
     */
    private function createJobs(User $customer, PickupSite $pickupSite, Collection $riders): Collection
    {
        // A rider is present from `assigned` onward; a pickup timestamp exists
        // from `picked_up` onward.
        $assignedFrom = [
            JobStatus::Assigned, JobStatus::EnRoutePickup, JobStatus::AtPickup,
            JobStatus::PickedUp, JobStatus::EnRouteDrop, JobStatus::OnSite,
            JobStatus::Delivered, JobStatus::Failed, JobStatus::Returning, JobStatus::Returned,
        ];
        $pickedUpFrom = [
            JobStatus::PickedUp, JobStatus::EnRouteDrop, JobStatus::OnSite,
            JobStatus::Delivered, JobStatus::Failed, JobStatus::Returning, JobStatus::Returned,
        ];

        $jobs = new Collection;
        $riderIndex = 0;

        foreach (JobStatus::cases() as $status) {
            $hasRider = in_array($status, $assignedFrom, true);
            $rider = $hasRider ? $riders[$riderIndex++ % $riders->count()] : null;

            $job = Job::factory()
                ->for($customer, 'customer')
                ->for($pickupSite, 'pickupSite')
                ->status($status)
                ->create([
                    'window' => $status === JobStatus::Booked ? JobWindow::SameDay : JobWindow::cases()[array_rand(JobWindow::cases())],
                    'assigned_rider_id' => $rider?->id,
                    'assigned_at' => $hasRider ? now()->subHours(4) : null,
                    'is_active_for_rider' => $hasRider && ! $status->isTerminal(),
                    'queue_position' => $hasRider && ! $status->isTerminal() ? 1 : null,
                    'eta_at' => in_array($status, JobStatus::liveMapStatuses(), true) ? now()->addHour() : null,
                    'booked_at' => now()->subHours(6),
                    'picked_up_at' => in_array($status, $pickedUpFrom, true) ? now()->subHours(3) : null,
                    'delivered_at' => $status === JobStatus::Delivered ? now()->subHour() : null,
                    'cancelled_at' => $status === JobStatus::Cancelled ? now()->subHours(5) : null,
                ]);

            $job->partLine()->create([
                'name' => 'Alternator '.strtoupper(substr(md5((string) $job->id), 0, 5)),
                'sku' => 'ALT-'.str_pad((string) $job->id, 4, '0', STR_PAD_LEFT),
                'quantity' => 1,
                'serial' => 'SN-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT),
            ]);

            $job->timelineEvents()->create([
                'type' => 'booked',
                'actor_role' => 'customer',
                'description' => 'Job booked by customer.',
                'meta' => ['window' => $job->window->value],
            ]);

            if ($hasRider) {
                $job->timelineEvents()->create([
                    'type' => 'assigned',
                    'actor_role' => 'ops',
                    'description' => "Assigned to {$rider->name}.",
                    'meta' => ['rider_id' => $rider->id],
                ]);
            }

            $job->timelineEvents()->create([
                'type' => 'status_changed',
                'actor_role' => $hasRider ? 'rider' : 'system',
                'description' => "Status is now {$status->value}.",
                'meta' => ['status' => $status->value],
            ]);

            $jobs->put($status->value, $job);
        }

        return $jobs;
    }

    /**
     * Attach PODs, a return photo, a fail record, notifications, and one active
     * recipient magic-link session — all scoped to the seeded jobs only.
     *
     * @param  Collection<string, Job>  $jobs
     */
    private function attachOutcomes(Collection $jobs, MagicLinkService $magicLinks): void
    {
        $delivered = $jobs->get(JobStatus::Delivered->value);
        if ($delivered !== null) {
            $delivered->pod()->create([
                'photo_path' => "pods/job-{$delivered->id}.jpg",
                'signature_path' => "signatures/job-{$delivered->id}.png",
            ]);
            $delivered->notifications()->create([
                'user_id' => $delivered->customer_id,
                'role' => UserRole::Customer,
                'channel' => NotificationChannel::InApp,
                'message' => 'Your delivery is complete.',
                'read_at' => now(),
            ]);
        }

        $failed = $jobs->get(JobStatus::Failed->value);
        if ($failed !== null) {
            $failed->failRecords()->create([
                'reason' => FailReason::NoContact,
                'note' => 'Recipient did not answer on site.',
            ]);
            $failed->notifications()->create([
                'role' => UserRole::Ops,
                'channel' => NotificationChannel::InApp,
                'message' => 'Delivery attempt failed: no contact.',
            ]);
        }

        // The return photo is optional; a seeded returned job still includes one.
        $returned = $jobs->get(JobStatus::Returned->value);
        $returned?->returnPhotos()->create(['photo_path' => "returns/job-{$returned->id}.jpg"]);

        // One active recipient magic-link session on an in-flight job.
        $inFlight = $jobs->get(JobStatus::EnRouteDrop->value);
        if ($inFlight !== null) {
            $session = $magicLinks->issueFor($inFlight);
            $inFlight->notifications()->create([
                'role' => null,
                'channel' => NotificationChannel::Sms,
                'message' => 'Your parcel is on the way. Track it here.',
                'magic_link' => $magicLinks->urlFor($session),
            ]);
        }
    }

    /**
     * One agent action log per agent type, attached to seeded jobs only.
     *
     * @param  Collection<string, Job>  $jobs
     * @param  Collection<int, User>  $riders
     */
    private function seedAgentLogs(Collection $jobs, Collection $riders): void
    {
        $jobs->get(JobStatus::Assigned->value)?->agentActionLogs()->create([
            'agent' => AgentType::Dispatch,
            'goal' => 'Assign the fastest available rider.',
            'last_action' => 'Selected rider from the assignable pool.',
            'meta' => ['candidates' => $riders->pluck('id')->all()],
        ]);

        $jobs->get(JobStatus::Failed->value)?->agentActionLogs()->create([
            'agent' => AgentType::Exception,
            'goal' => 'Resolve the failed delivery.',
            'last_action' => 'Proposed a reattempt window.',
            'pending_ask' => 'Awaiting customer confirmation to reattempt.',
        ]);

        $jobs->get(JobStatus::EnRouteDrop->value)?->agentActionLogs()->create([
            'agent' => AgentType::CustomerOps,
            'goal' => 'Keep the customer informed in transit.',
            'last_action' => 'Sent an in-transit tracking notification.',
        ]);
    }
}
