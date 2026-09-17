<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Custom notifications table (not Laravel's polymorphic notifications).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Target role for the notification (customer | rider | ops), optional.
            $table->string('role')->nullable();
            // Backed by App\Enums\NotificationChannel: in_app | sms.
            $table->string('channel')->default('in_app');
            $table->text('message');
            // Signed magic-link URL delivered to a recipient, when applicable.
            $table->text('magic_link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
