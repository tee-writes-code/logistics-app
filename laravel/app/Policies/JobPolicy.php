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
     * The owning customer or the assigned rider may update a job.
     */
    public function update(User $user, Job $job): bool
    {
        return $this->owns($user, $job) || $this->isAssignedRider($user, $job);
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
