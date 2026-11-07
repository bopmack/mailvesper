<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery reports for workspaces that send over SMTP: the relay provider's event webhook (SendGrid, Mailgun,
     * Postmark, Brevo, SparkPost) brings back deliveries, bounces and spam complaints SMTP itself never reports.
     * Mailboxes also count the feedback-loop complaints they read.
     */
    public function up(): void
    {
        Schema::create('delivery_hooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 12);
            $table->string('token_hash', 64)->unique(); // sha256: the lookup
            $table->text('token');                      // encrypted: shown again on the page
            $table->text('signing_key')->nullable(); // encrypted; verifies the provider's signature when set
            $table->unsignedInteger('events')->default(0);
            $table->dateTime('last_event_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->unsignedInteger('complaints')->default(0)->after('bounces');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn('complaints'));
        Schema::dropIfExists('delivery_hooks');
    }
};
