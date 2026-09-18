<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an AgentAsk. A pending ask blocks until Ops or the owning
 * customer confirms or rejects it; the first writer wins.
 */
enum AgentAskStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
