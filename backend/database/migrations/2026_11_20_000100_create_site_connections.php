<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Connect to MailVesper" from a website (a Laravel site with the MailVesper package): the owner approves the
// site in MailVesper, the site exchanges a one-time code (PKCE) for its own API key. One row per approval.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('site_url', 255);           // https://shop.example.com
            $table->string('name', 120);
            $table->string('redirect_uri', 500);
            $table->string('code_hash', 64)->nullable()->unique();
            $table->string('code_challenge', 128)->nullable();
            $table->dateTime('code_expires_at')->nullable();
            $table->ulid('api_key_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'site_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_connections');
    }
};
