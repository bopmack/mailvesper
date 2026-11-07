<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's own link-tracking domain (links.acme.com instead of the shared tracking address). Used only
     * while its CNAME points at MailVesper and it answers over HTTPS with a valid certificate.
     */
    public function up(): void
    {
        Schema::create('tracking_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host', 253);
            $table->string('status', 12)->default('pending'); // pending | active
            $table->boolean('ses_ready')->default(false);     // the SES configuration set without SES's own tracking exists
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_domains');
    }
};
