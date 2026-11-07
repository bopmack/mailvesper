<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's mailbox, read over IMAP without changing anything in it: replies to its emails, bounce
     * notices that arrive later by email, and DMARC aggregate reports.
     */
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host', 255);
            $table->unsignedSmallInteger('port')->default(993);
            $table->string('encryption', 8)->default('ssl'); // ssl (993) | tls (STARTTLS on 143)
            $table->string('username', 255);
            $table->text('password');                        // encrypted
            $table->string('folder', 100)->default('INBOX');
            $table->boolean('read_replies')->default(true);
            $table->boolean('read_bounces')->default(true);
            $table->boolean('read_dmarc')->default(true);
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->nullable(); // newest message already looked at
            $table->unsignedInteger('replies')->default(0);
            $table->unsignedInteger('bounces')->default(0);
            $table->unsignedInteger('reports')->default(0);
            $table->dateTime('last_checked_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
