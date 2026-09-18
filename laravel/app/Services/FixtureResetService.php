<?php

declare(strict_types=1);

namespace App\Services;

use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo fixture reset (iter-5). Truncates the domain tables in one transaction and
 * re-runs the foundation seeders so a demo can be replayed from a clean, known
 * state. It also clears any clock override. Ops-gated and non-production only —
 * enforced by the `demo` middleware on the calling route. Never touches `.env`;
 * it re-seeds through the same idempotent foundation seeders.
 */
class FixtureResetService
{
    /**
     * Domain tables cleared on reset, child-before-parent so foreign keys stay
     * satisfied. Constraint checks are never disabled — the order alone keeps every
     * delete valid. Framework tables (users kept apart), cache, sessions and
     * demo_settings are handled separately.
     *
     * @var array<int, string>
     */
    private const DOMAIN_TABLES = [
        'agent_action_logs',
        'agent_asks',
        'recipient_actions',
        'magic_link_sessions',
        'notifications',
        'fail_records',
        'return_photos',
        'pods',
        'job_timeline_events',
        'part_lines',
        'jobs',
        'saved_drops',
        'pickup_sites',
    ];

    public function __construct(private readonly ClockService $clock) {}

    /**
     * Clear the domain tables and the demo users, re-seed the foundation, and
     * clear the clock override. Idempotent: safe to run mid-path. Rows are
     * deleted child-before-parent (and the demo users last, after every row that
     * referenced them is gone), so foreign keys stay satisfied without toggling
     * constraint checks.
     *
     * The deletes AND the reseed run in one transaction: if a seeder throws, the
     * whole reset rolls back and the previous fixtures are left intact, rather
     * than leaving the app emptied with no seed data.
     */
    public function reset(): void
    {
        DB::transaction(function (): void {
            foreach (self::DOMAIN_TABLES as $table) {
                DB::table($table)->delete();
            }

            // The demo accounts (ops/customer/rider1..5) are re-created
            // idempotently by RoleSeeder below.
            DB::table('users')->whereIn('email', $this->demoUserEmails())->delete();

            // Reseed inside the same transaction so a failed reset is recoverable.
            app(RoleSeeder::class)->run();
            app(LogisticsDemoSeeder::class)->run(app(MagicLinkService::class));
        });

        $this->clock->clearOverride();
    }

    /**
     * @return array<int, string>
     */
    private function demoUserEmails(): array
    {
        return [
            'ops@logistics.test',
            'customer@logistics.test',
            'rider1@logistics.test',
            'rider2@logistics.test',
            'rider3@logistics.test',
            'rider4@logistics.test',
            'rider5@logistics.test',
        ];
    }
}
