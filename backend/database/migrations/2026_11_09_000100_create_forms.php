<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Signup forms (hosted page, embed or plain HTML) and their double opt-in submissions. */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('public_key', 24)->unique(); // in the form's URL and embed code
            $table->foreignUlid('list_id')->nullable()->constrained('lists')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->json('fields')->nullable();          // [{key, label, required}] besides email
            $table->string('heading', 150)->nullable();
            $table->string('intro', 500)->nullable();
            $table->string('button', 40)->nullable();
            $table->string('success_message', 500)->nullable();
            $table->string('redirect_url', 500)->nullable();
            $table->boolean('double_opt_in')->default(true);
            $table->string('from_email', 254)->nullable(); // sender of the confirmation email
            $table->string('from_name', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('submissions')->default(0);
            $table->unsignedInteger('confirmed')->default(0);
            $table->timestamps();
            $table->index('workspace_id');
        });
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_id')->constrained()->cascadeOnDelete();
            $table->ulid('workspace_id')->index();
            $table->string('email', 254);
            $table->json('data')->nullable();
            $table->string('token_hash', 64)->nullable()->unique(); // sha256 of the confirmation token
            $table->string('status', 12);                          // pending | confirmed | added
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->index(['form_id', 'email']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
