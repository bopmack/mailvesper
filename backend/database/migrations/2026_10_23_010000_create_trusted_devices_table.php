<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Trust this device for 30 days" after two-step verification. Only a hash of the token is stored.
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
