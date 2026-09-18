<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fail_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            // Rider (or ops) who recorded the failure.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // Backed by App\Enums\FailReason: closed | wrong_site | no_contact.
            $table->string('reason');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fail_records');
    }
};
