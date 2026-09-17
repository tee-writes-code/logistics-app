<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_domain_tables_are_migrated(): void
    {
        $tables = [
            'users', 'pickup_sites', 'saved_drops', 'jobs', 'part_lines',
            'job_timeline_events', 'notifications', 'pods', 'return_photos',
            'fail_records', 'magic_link_sessions', 'agent_action_logs',
            'recipient_actions', 'personal_access_tokens', 'queue_jobs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_users_table_has_a_role_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'role'));
    }
}
