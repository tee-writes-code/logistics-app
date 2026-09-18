<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records who captured each POD, fail record, and return photo. The rider
     * (or ops) actor is persisted alongside the artifact so later iterations can
     * attribute execution without re-reading the timeline.
     */
    public function up(): void
    {
        Schema::table('pods', function (Blueprint $table): void {
            $table->foreignId('delivered_by')->nullable()->after('job_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('fail_records', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->after('job_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('return_photos', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->after('job_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivered_by');
        });

        Schema::table('fail_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
        });

        Schema::table('return_photos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
        });
    }
};
