<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only log of AI agent activity. No updated_at column.
     */
    public function up(): void
    {
        Schema::create('agent_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained()->cascadeOnDelete();
            // Backed by App\Enums\AgentType: dispatch | exception | customer_ops.
            $table->string('agent');
            $table->text('goal');
            $table->text('last_action')->nullable();
            $table->text('pending_ask')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_action_logs');
    }
};
