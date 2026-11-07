<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide settings the admin panel edits (AI provider, model, keys…). Secrets are stored encrypted.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->boolean('secret')->default(false);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        // Every AI call: who, which feature, which model, how many tokens (and the provider's cost when it reports one).
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id')->nullable()->index();
            $table->ulid('user_id')->nullable();
            $table->string('feature', 40);
            $table->string('provider', 20);
            $table->string('model', 120);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('ok')->default(true);
            $table->string('error', 255)->nullable();
            $table->dateTime('created_at');
            $table->index(['workspace_id', 'created_at']);
        });

        // Copilot diagnoses (kept for the score history).
        Schema::create('copilot_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable(); // null = not enough sending yet
            $table->json('findings');
            $table->json('passed');
            $table->json('summary');
            $table->dateTime('created_at');
            $table->index(['workspace_id', 'created_at']);
        });

        // DMARC aggregate reports (RUA), uploaded or fetched from the report mailbox.
        Schema::create('dmarc_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('org_name', 150);
            $table->string('report_id', 200);
            $table->string('domain', 253);
            $table->string('policy', 16)->nullable();
            $table->dateTime('date_begin');
            $table->dateTime('date_end');
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->dateTime('created_at');
            $table->unique(['workspace_id', 'org_name', 'report_id']);
            $table->index(['workspace_id', 'domain', 'date_end']);
        });
        Schema::create('dmarc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('dmarc_reports')->cascadeOnDelete();
            $table->string('source_ip', 45);
            $table->unsignedInteger('count');
            $table->string('disposition', 16)->nullable();
            $table->string('dkim', 8)->nullable();   // policy-evaluated: pass | fail
            $table->string('spf', 8)->nullable();
            $table->string('header_from', 253)->nullable();
            $table->string('dkim_domain', 253)->nullable();
            $table->string('spf_domain', 253)->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('brand'); // sending + deliverability switches (e.g. gentle Outlook pacing)
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->ulid('import_id')->nullable()->after('source')->index(); // the import that created this contact
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['import_id']);
            $table->dropColumn('import_id');
        });
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('settings'));
        foreach (['dmarc_records', 'dmarc_reports', 'copilot_scans', 'ai_usage', 'platform_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
