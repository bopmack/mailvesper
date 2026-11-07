<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sending through the customer's own SMTP server, as an alternative to Amazon SES (one method per workspace).
 * Senders are proven per domain (a TXT record) or per address (a code emailed through that SMTP server).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host', 255);
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 10);            // tls (STARTTLS) | ssl (implicit TLS) | none
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();          // encrypted
            $table->unsignedSmallInteger('max_per_second')->default(5);
            $table->unsignedInteger('max_per_day')->nullable();
            $table->dateTime('verified_at')->nullable();   // last successful connection test
            $table->string('last_error', 500)->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sender_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                    // domain | email
            $table->string('value', 254);
            $table->string('status', 10)->default('pending'); // pending | verified
            $table->string('token', 64);                   // the TXT value (domain), or a hash of the emailed code (email)
            $table->dateTime('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->json('dns_check')->nullable();         // spf / dkim / dmarc advice for domains
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind', 'value']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('sending_method', 10)->nullable(); // null = Amazon SES when connected (installations before SMTP)
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('sending_method'));
        Schema::dropIfExists('sender_identities');
        Schema::dropIfExists('smtp_connections');
    }
};
