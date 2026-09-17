<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Domain courier jobs. The framework's queue table was renamed to
     * `queue_jobs` so this table can own the `jobs` name.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();

            // The customer who booked the job.
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            // Saved pickup site (kept on the customer account).
            $table->foreignId('pickup_site_id')->nullable()->constrained('pickup_sites')->nullOnDelete();

            // Drop details are captured on the job (recipient has no account).
            $table->text('drop_address');
            $table->string('drop_contact_name');
            $table->string('drop_contact_phone');

            $table->text('notes')->nullable();
            $table->text('instructions')->nullable();

            // Backed by App\Enums\JobWindow: same_day | next.
            $table->string('window')->default('same_day');
            // Backed by App\Enums\JobStatus (booked..cancelled).
            $table->string('status')->default('booked')->index();

            // Rider assignment / dispatch queue.
            $table->foreignId('assigned_rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('queue_position')->nullable();
            $table->boolean('is_active_for_rider')->default(false);
            $table->timestamp('eta_at')->nullable();

            // Lifecycle timestamps.
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
