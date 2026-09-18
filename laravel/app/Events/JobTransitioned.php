<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by JobStatusService after a status transition commits. This is the
 * seam later iterations hang behaviour on: iter-3 attaches notification and
 * live-map listeners, iter-4's agents call JobStatusService directly. iter-2
 * only fires the event; it registers no listeners.
 *
 * It implements ShouldDispatchAfterCommit so the listeners never run until the
 * REAL outermost transaction commits. JobStatusService already dispatches after
 * its own inner transaction, but an agent (e.g. ExceptionAgent::confirm) may wrap
 * transition() in an outer transaction; there the inner commit is only a
 * savepoint, so without this contract the listeners would fire while the outer
 * transaction is still open and could still roll back. With it, Laravel defers
 * dispatch to the outermost commit (and fires immediately when none is open).
 */
class JobTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Job $job,
        public readonly JobStatus $from,
        public readonly JobStatus $to,
        public readonly string $actorRole,
        public readonly array $payload = [],
    ) {}
}
