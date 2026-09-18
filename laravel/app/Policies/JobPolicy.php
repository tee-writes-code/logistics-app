<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Job;
use App\Models\User;

class JobPolicy
{
    /**
     * Ops can do anything with jobs.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isOps()) {
            return true;
        }

        return null;
    }

    /**
     * Any authenticated user may reach a job list action. This ONLY authorizes
     * access to the list; it does NOT scope the rows. Every index/list endpoint
     * MUST filter its query through Job::visibleTo($user) (never Job::all()),
     * otherwise a customer would see all customers' jobs and a rider would see
     * all riders' jobs.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Customers see their own jobs; riders see jobs assigned to them.
     */
    public function view(User $user, Job $job): bool
    {
        return $this->owns($user, $job) || $this->isAssignedRider($user, $job);
    }

    /**
     * Only customers book jobs.
     */
    public function create(User $user): bool
    {
        return $user->isCustomer();
    }

    /**
     * Only the owning customer (or ops via before()) may edit a job's
     * customer-owned fields (drop details, contact, instructions, notes) through
     * the customer PATCH route. The assigned rider drives the lifecycle through the
     * separate `execute` ability and must never mutate the customer's fields here.
     */
    public function update(User $user, Job $job): bool
    {
        return $this->owns($user, $job);
    }

    /**
     * The owning customer may cancel their job (ops via before()). The status
     * window (only before pickup) is enforced in the controller, not here.
     */
    public function cancel(User $user, Job $job): bool
    {
        return $this->owns($user, $job);
    }

    /**
     * A rider may execute (drive the lifecycle of) a job assigned to them.
     *
     * This gates ownership only, so a rider reaching another rider's job gets a
     * 403. Whether the job is the rider's ONE active job is a separate, softer
     * check enforced as a 409 in the rider endpoints (acting on a non-active but
     * owned job is a conflict, not a forbidden access).
     */
    public function execute(User $user, Job $job): bool
    {
        return $this->isAssignedRider($user, $job);
    }

    /**
     * Only ops may delete jobs (handled by before()).
     */
    public function delete(User $user, Job $job): bool
    {
        return false;
    }

    private function owns(User $user, Job $job): bool
    {
        return $user->isCustomer() && $job->customer_id === $user->id;
    }

    private function isAssignedRider(User $user, Job $job): bool
    {
        return $user->isRider() && $job->assigned_rider_id === $user->id;
    }
}
