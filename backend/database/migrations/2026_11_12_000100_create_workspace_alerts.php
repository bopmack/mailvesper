<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Warnings to a workspace's owners and admins before AWS (or their SMTP plan) limits stop their sending. */
    public function up(): void
    {
        Schema::create('workspace_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);        // quota, bounce, complaint, enforcement, sending_paused, smtp_daily
            $table->string('level', 8);        // warn | urgent
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->dateTime('raised_at');
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('cleared_at')->nullable();
            $table->unique(['workspace_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_alerts');
    }
};
