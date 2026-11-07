<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connected sites as LIVE audiences: MailVesper reads a site's users through its package when it needs them (counts,
 * browsing, sending) and never stores them as contacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->renameColumn('sync_url', 'audience_url');
            $table->renameColumn('sync_secret', 'audience_secret');
            $table->renameColumn('synced_at', 'audience_ok_at');
            $table->renameColumn('sync_error', 'audience_error');
        });
        Schema::table('site_connections', function (Blueprint $table) {
            $table->json('fields')->nullable()->after('audience_error'); // [{key, label, type}] the site can answer
        });
        Schema::table('segments', function (Blueprint $table) {
            $table->ulid('site_connection_id')->nullable()->after('workspace_id')->index(); // set: rules run on that site, live
        });
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->text('vars')->nullable(); // a site person's merge details, kept only until their email is built
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', fn (Blueprint $table) => $table->dropColumn('vars'));
        Schema::table('segments', function (Blueprint $table) {
            $table->dropIndex(['site_connection_id']);
            $table->dropColumn('site_connection_id');
        });
        Schema::table('site_connections', fn (Blueprint $table) => $table->dropColumn('fields'));
        Schema::table('site_connections', function (Blueprint $table) {
            $table->renameColumn('audience_url', 'sync_url');
            $table->renameColumn('audience_secret', 'sync_secret');
            $table->renameColumn('audience_ok_at', 'synced_at');
            $table->renameColumn('audience_error', 'sync_error');
        });
    }
};
