<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A DNS provider account a workspace lets MailVesper add its sending records to (Cloudflare, by API token). */
    public function up(): void
    {
        Schema::create('dns_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);   // cloudflare
            $table->text('token');            // encrypted
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'provider']);
        });
        Schema::table('domains', function (Blueprint $table) {
            $table->string('cloudflare_zone_id', 64)->nullable()->after('route53_zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('cloudflare_zone_id');
        });
        Schema::dropIfExists('dns_connections');
    }
};
