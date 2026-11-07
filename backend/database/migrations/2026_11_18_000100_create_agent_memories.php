<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An AI agent step's memory of one contact, as n8n's Simple Memory with a session key: the last exchanges under a
// key (shared by the agents that use the same key). It goes with the contact (deletes and GDPR erasure).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id')->index();
            $table->ulid('contact_id');
            $table->string('memory_key', 40);
            $table->string('role', 16); // user | assistant
            $table->text('content');
            $table->ulid('journey_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['contact_id', 'memory_key', 'id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memories');
    }
};
