<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imports from another email provider (Mailchimp, SendGrid…). Run in resumable chunks; suppressions always first.
        Schema::create('provider_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('status', 16)->default('connected'); // connected | importing | done | failed | cancelled
            $table->text('credentials'); // encrypted JSON; wiped when the import finishes
            $table->string('account_name', 200)->nullable();
            $table->json('discovery')->nullable(); // what was found: counts, lists, notes
            $table->json('options')->nullable();   // what to bring: contacts, templates, lists, tag
            $table->string('stage', 20)->nullable();
            $table->json('cursor')->nullable();
            $table->json('progress')->nullable(); // stage → {done, total, skipped}
            $table->json('state')->nullable();    // list map, field map…
            $table->json('notes')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_imports');
    }
};
