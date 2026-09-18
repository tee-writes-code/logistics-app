<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Demo-only key/value store (iter-5). Currently it holds the persisted clock
     * override that ClockService::now() reads to advance the demo clock. It is
     * additive: no product table depends on it, and an empty table means real
     * time. sqlite-compatible so the in-memory test DB gets it too.
     */
    public function up(): void
    {
        Schema::create('demo_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_settings');
    }
};
