<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Check my setup": a real email sent from the workspace's sender, through its own SES or SMTP route, to the
     * installation's check mailbox, then read back and analysed (path, SPF, DKIM, DMARC, blocklists).
     */
    public function up(): void
    {
        Schema::create('deliverability_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('from_email', 254);
            $table->ulid('message_id')->nullable();
            $table->string('token', 32)->unique();
            $table->string('status', 10);       // waiting | done | failed
            $table->json('result')->nullable();
            $table->string('error', 300)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('finished_at')->nullable();
            $table->index(['workspace_id', 'domain', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverability_checks');
    }
};
