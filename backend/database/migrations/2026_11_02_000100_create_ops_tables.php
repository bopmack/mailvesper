<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Operations: errors grouped by cause, slow queries, heartbeats/alert state, and backups with restore drills.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 40)->unique(); // sha1(class + file + line)
            $table->string('class', 255);
            $table->text('message');
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('url', 500)->nullable();
            $table->json('trace')->nullable(); // first frames, for context
            $table->unsignedInteger('count')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at')->index();
            $table->dateTime('resolved_at')->nullable();
        });
        Schema::create('ops_slow_queries', function (Blueprint $table) {
            $table->id();
            $table->text('sql');
            $table->unsignedInteger('ms');
            $table->string('url', 500)->nullable();
            $table->dateTime('created_at')->index();
        });
        Schema::create('ops_state', function (Blueprint $table) {
            $table->string('key', 80)->primary(); // heartbeat, alert:<rule>, …
            $table->json('value')->nullable();
            $table->dateTime('updated_at');
        });
        Schema::create('ops_backups', function (Blueprint $table) {
            $table->id();
            $table->string('file', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('tables')->nullable(); // row count per table at backup time
            $table->string('status', 10); // ok | failed
            $table->string('error', 500)->nullable();
            $table->string('drill_status', 10)->nullable(); // ok | failed
            $table->dateTime('drill_at')->nullable();
            $table->string('drill_detail', 500)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['ops_backups', 'ops_state', 'ops_slow_queries', 'ops_errors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
