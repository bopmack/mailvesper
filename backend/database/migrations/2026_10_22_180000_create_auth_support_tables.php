<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time 6-digit codes (email verification, passwordless sign-in). Only a hash is stored.
        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('purpose', 20);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['email', 'purpose', 'created_at']);
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->foreignUlid('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('send_source', 20)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id')->nullable()->index();
            $table->ulid('user_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('email_codes');
    }
};
