<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only audit trail for a job. No updated_at column: events are
     * never mutated or deleted (enforced in the model).
     */
    public function up(): void
    {
        Schema::create('job_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // Attribution: a human actor role, or an AI agent (one of the two).
            $table->string('actor_role')->nullable();
            $table->string('agent')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_timeline_events');
    }
};
