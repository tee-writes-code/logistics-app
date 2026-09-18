<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Confirmation requests an agent raises when a plan needs a human decision.
     * A pending ask blocks until Ops or the owning customer resolves it.
     */
    public function up(): void
    {
        Schema::create('agent_asks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            // Backed by App\Enums\AgentType (which agent raised the ask).
            $table->string('agent');
            // Backed by App\Enums\AgentAskType: next | return | unsafe.
            $table->string('type');
            // Backed by App\Enums\AgentAskStatus: pending | confirmed | rejected.
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_asks');
    }
};
