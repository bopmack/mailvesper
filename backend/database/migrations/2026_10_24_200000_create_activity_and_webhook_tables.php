<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The timeline of every message: SES events plus MailVesper's own (accepted, suppressed, failed, retrying).
        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->ulid('message_id')->nullable();
            $table->unsignedBigInteger('ses_event_id')->nullable()->unique();
            $table->string('type', 24);
            $table->boolean('machine')->default(false); // Apple privacy open, security-scanner open/click
            $table->json('data')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['message_id', 'occurred_at']);
            $table->index(['workspace_id', 'type', 'occurred_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider', 16)->nullable()->after('to_email');
            $table->dateTime('opened_at')->nullable()->after('delivered_at');
            $table->dateTime('clicked_at')->nullable()->after('opened_at');
            $table->unsignedInteger('open_count')->default(0)->after('clicked_at');
            $table->unsignedInteger('machine_open_count')->default(0)->after('open_count');
            $table->unsignedInteger('click_count')->default(0)->after('machine_open_count');
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('description', 120)->nullable();
            $table->json('events'); // list of event types, or ["*"]
            $table->text('secret'); // encrypted
            $table->boolean('enabled')->default(true);
            $table->string('disabled_reason', 255)->nullable();
            $table->unsignedInteger('failure_streak')->default(0);
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_failure_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->ulid('workspace_id')->index();
            $table->string('event_type', 40);
            $table->ulid('message_id')->nullable();
            $table->json('payload');
            $table->string('status', 16)->default('pending'); // pending | retrying | success | failed | cancelled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_body', 500)->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropColumn(['provider', 'opened_at', 'clicked_at', 'open_count', 'machine_open_count', 'click_count']);
        });
        Schema::dropIfExists('message_events');
    }
};
