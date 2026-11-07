<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 16)->default('draft'); // draft | scheduled | sending | paused | sent | cancelled
            $table->string('audience_type', 16)->nullable(); // all | list | segment
            $table->ulid('audience_id')->nullable();
            $table->boolean('exclude_dormant')->default(true);
            $table->ulid('topic_id')->nullable();
            $table->string('from_email', 254)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to', 254)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('subject_b', 255)->nullable();
            $table->string('preheader', 300)->nullable();
            $table->longText('doc')->nullable();
            $table->longText('html')->nullable();
            $table->decimal('size_kb', 8, 1)->nullable();
            $table->json('checks')->nullable();
            $table->boolean('ab_enabled')->default(false);
            $table->unsignedTinyInteger('ab_test_pct')->default(20);
            $table->string('ab_metric', 8)->default('opens'); // opens | clicks
            $table->unsignedTinyInteger('ab_wait_hours')->default(4);
            $table->char('ab_winner', 1)->nullable();
            $table->dateTime('ab_decided_at')->nullable();
            $table->string('send_mode', 12)->default('now'); // now | scheduled | local
            $table->dateTime('scheduled_at')->nullable(); // UTC; for local sends, the earliest local slot
            $table->string('local_time', 5)->nullable(); // HH:MM in each recipient's time zone
            $table->date('local_date')->nullable();
            $table->string('timezone', 64)->nullable(); // fallback zone for recipients without one
            $table->unsignedInteger('recipients_count')->default(0);
            $table->string('share_token', 40)->nullable()->unique();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });

        // The audience, frozen when the campaign starts.
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->ulid('contact_id')->nullable();
            $table->string('email', 254);
            $table->char('variant', 1)->nullable(); // a | b | null (holdout until the A/B winner is picked)
            $table->string('status', 12)->default('pending'); // pending | sent | skipped | cancelled
            $table->dateTime('send_at')->nullable();
            $table->ulid('message_id')->nullable();
            $table->string('skip_reason', 40)->nullable();
            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status', 'send_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->ulid('campaign_id')->nullable()->after('batch_id')->index();
            $table->char('variant', 1)->nullable()->after('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn(['campaign_id', 'variant']);
        });
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
