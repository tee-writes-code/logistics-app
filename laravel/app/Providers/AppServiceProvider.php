<?php

namespace App\Providers;

use App\Events\JobTransitioned;
use App\Listeners\HandleJobTransition;
use App\Listeners\RunExceptionHooks;
use App\Models\AgentAsk;
use App\Models\User;
use App\Policies\AgentAskPolicy;
use App\Services\ClockService;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A single ClockService per request so now()/setOverride()/clearOverride()
        // share one override memo; otherwise each injection carries its own stale
        // copy and a mid-request override change would not be seen consistently.
        $this->app->singleton(ClockService::class);

        // Register the JobTransitioned listeners explicitly (below) instead of by
        // filesystem discovery, so their order is deterministic. Discovery would
        // also register them, double-firing every side effect, so it is disabled.
        EventServiceProvider::disableEventDiscovery();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // JobTransitioned listeners run in a fixed order: HandleJobTransition
        // (magic link + notifications) composes the customer-facing copy from the
        // just-committed state FIRST, then RunExceptionHooks (failed -> Exception
        // agent) may advance the job. Registration order is dispatch order, so this
        // is deterministic rather than dependent on discovery order.
        Event::listen(JobTransitioned::class, HandleJobTransition::class);
        Event::listen(JobTransitioned::class, RunExceptionHooks::class);

        // Ops supervisory console (board, assignment, reorder, on-behalf CRUD).
        Gate::define('ops-console', static fn (User $user): bool => $user->isOps());

        Gate::policy(AgentAsk::class, AgentAskPolicy::class);
    }
}
