<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journeys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 16)->default('draft'); // draft | active | paused | archived
            $table->json('graph');                           // the editable draft: {nodes: [...], edges: [...]}
            $table->json('settings')->nullable();            // reentry, cooldown_days
            $table->unsignedInteger('draft_rev')->default(1); // bumps on every save, so "unpublished changes" is exact
            $table->ulid('live_version_id')->nullable();
            $table->unsignedInteger('live_rev')->nullable(); // draft_rev that was published
            $table->string('hook_token', 32)->unique();      // incoming-webhook trigger URL
            $table->string('secret', 64);                    // signs outgoing webhook steps
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('activated_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        // Each publish freezes the graph and every email's content. Runs follow the version they started on.
        Schema::create('journey_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('journey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('graph');
            $table->json('emails');   // node id → {subject, preheader, from_email, from_name, reply_to, html, skip_recent_hours}
            $table->json('triggers'); // flattened trigger index for fast matching
            $table->foreignUlid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->unique(['journey_id', 'number']);
        });

        // Draft content of "Send email" steps (kept out of the graph: designs are large).
        Schema::create('journey_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('journey_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 24);
            $table->string('subject', 255)->nullable();
            $table->string('preheader', 300)->nullable();
            $table->string('from_email', 254)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to', 254)->nullable();
            $table->json('doc')->nullable();
            $table->longText('html')->nullable();
            $table->decimal('size_kb', 8, 1)->nullable();
            $table->json('checks')->nullable();
            $table->timestamps();
            $table->unique(['journey_id', 'node_id']);
        });

        // One contact's trip through one journey.
        Schema::create('journey_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('journey_id')->constrained()->cascadeOnDelete();
            $table->ulid('version_id');
            $table->ulid('workspace_id')->index();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12); // waiting | running | completed | goal | exited | failed
            $table->string('current_node', 24)->nullable();
            $table->string('trigger_node', 24)->nullable();
            $table->string('armed', 24)->nullable();      // the wait step this run is paused at
            $table->string('signal', 8)->nullable();      // 'yes' when the awaited event arrived
            $table->dateTime('wake_at')->nullable();      // continue at (wait, send window, wait-for timeout)
            $table->string('waiting_for', 100)->nullable(); // event name a "wait for event" step listens for
            $table->string('dedupe_key', 120)->nullable();
            $table->json('context')->nullable();          // the event that started the run
            $table->string('exit_reason', 200)->nullable();
            $table->dateTime('entered_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'wake_at']);
            $table->index(['journey_id', 'contact_id']);
            $table->index(['workspace_id', 'contact_id', 'status']);
            $table->unique(['journey_id', 'dedupe_key']);
        });

        // Every step a run passed: drives per-step and per-connection counts.
        Schema::create('journey_steps', function (Blueprint $table) {
            $table->id();
            $table->ulid('journey_id');
            $table->ulid('run_id')->index();
            $table->string('node_id', 24);
            $table->string('next_node_id', 24)->nullable();
            $table->string('outcome', 24)->nullable(); // yes | no | a | b | sent | skipped | goal | …
            $table->string('detail', 255)->nullable();
            $table->ulid('message_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['journey_id', 'node_id']);
        });

        // Things contacts did: the track API, contact changes and email engagement. Triggers, waits and goals read it.
        Schema::create('contact_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->ulid('contact_id');
            $table->string('name', 100);
            $table->json('properties')->nullable();
            $table->string('source', 16); // api | contact | email | webhook | system
            $table->dateTime('occurred_at');
            $table->index(['contact_id', 'name', 'occurred_at']);
            $table->index(['workspace_id', 'name', 'occurred_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->ulid('journey_id')->nullable()->after('variant')->index();
            $table->string('journey_node', 24)->nullable()->after('journey_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['journey_id']);
            $table->dropColumn(['journey_id', 'journey_node']);
        });
        Schema::dropIfExists('contact_events');
        Schema::dropIfExists('journey_steps');
        Schema::dropIfExists('journey_runs');
        Schema::dropIfExists('journey_emails');
        Schema::dropIfExists('journey_versions');
        Schema::dropIfExists('journeys');
    }
};
