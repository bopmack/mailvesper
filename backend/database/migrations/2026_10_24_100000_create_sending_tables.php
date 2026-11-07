<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('prefix', 16); // first characters, for display
            $table->string('key_hash', 64)->unique();
            $table->string('scope', 16)->default('send'); // send | full
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        // One row per recipient.
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_code', 16)->unique();
            $table->ulid('batch_id')->nullable()->index();
            $table->string('source', 16); // api | test | campaign
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('type', 16)->default('transactional'); // transactional | marketing
            $table->string('from_email', 254);
            $table->string('from_name', 120)->nullable();
            $table->string('to_email', 254);
            $table->json('reply_to')->nullable();
            $table->string('subject', 998);
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->json('headers')->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->string('ses_message_id', 128)->nullable()->index();
            $table->string('unsubscribe_token', 40)->nullable()->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'to_email']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('reason', 16); // hard_bounce | complaint | unsubscribe | manual
            $table->string('detail', 255)->nullable();
            $table->ulid('message_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique(['workspace_id', 'email']);
            $table->index(['workspace_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('api_keys');
    }
};
