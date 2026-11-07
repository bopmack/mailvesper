<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One AWS SES connection per workspace (multi-region comes later). Keys are encrypted with APP_KEY.
        Schema::create('ses_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('auth_type', 16)->default('keys');
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->string('region', 32);
            $table->string('aws_account_id', 16)->nullable();
            $table->string('iam_arn', 255)->nullable();
            $table->string('status', 16)->default('connecting'); // connecting | active | error
            $table->boolean('production_access')->default(false);
            $table->boolean('sending_enabled')->default(false);
            $table->string('enforcement_status', 32)->nullable();
            $table->double('max_24h_send')->default(0);
            $table->double('max_send_rate')->default(0);
            $table->double('sent_last_24h')->default(0);
            $table->double('bounce_rate')->nullable();
            $table->double('complaint_rate')->nullable();
            $table->string('review_status', 32)->nullable();
            $table->string('configuration_set', 64)->nullable();
            $table->string('sns_topic_arn', 255)->nullable();
            $table->string('subscription_arn', 255)->nullable();
            $table->dateTime('subscription_confirmed_at')->nullable();
            $table->text('webhook_token'); // encrypted; reused when setup is re-run
            $table->string('webhook_token_hash', 64)->unique();
            $table->json('setup_steps')->nullable();
            $table->json('permissions')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->foreignUlid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 253);
            $table->string('region', 32);
            $table->string('status', 16)->default('pending'); // pending | verified | failed
            $table->json('dkim_tokens')->nullable();
            $table->string('dkim_status', 32)->nullable();
            $table->string('mail_from_domain', 253)->nullable();
            $table->string('mail_from_status', 32)->nullable();
            $table->json('dns_check')->nullable();
            $table->string('dns_provider', 16)->nullable(); // route53 | null (manual)
            $table->string('route53_zone_id', 64)->nullable();
            $table->boolean('imported')->default(false);
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        // Raw SES events from SNS. Phase 4 turns these into the activity timeline.
        Schema::create('ses_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id')->index();
            $table->string('event_type', 32);
            $table->string('message_id', 128)->nullable()->index();
            $table->string('sns_message_id', 64)->unique();
            $table->json('recipients')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('payload');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_events');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('ses_connections');
    }
};
