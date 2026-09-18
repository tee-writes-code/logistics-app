<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentAsk;
use App\Models\User;

/**
 * Who may see and resolve agent asks: Ops sees and resolves all; a customer sees
 * and resolves only asks on their own jobs.
 */
class AgentAskPolicy
{
    /**
     * Ops can do anything with asks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isOps()) {
            return true;
        }

        return null;
    }

    /**
     * Any authenticated user may reach the pending-asks list; the query is scoped
     * to their own jobs for customers (Ops is handled by before()).
     */
    public function viewAny(User $user): bool
    {
        return $user->isOps() || $user->isCustomer();
    }

    public function resolve(User $user, AgentAsk $ask): bool
    {
        return $user->isCustomer() && $ask->job?->customer_id === $user->id;
    }
}
