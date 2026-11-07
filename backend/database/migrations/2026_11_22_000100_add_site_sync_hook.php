<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where to ask a connected site for its latest changes right before a campaign freezes its audience. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->string('sync_url', 500)->nullable()->after('api_key_id');
            $table->text('sync_secret')->nullable()->after('sync_url'); // encrypted
            $table->timestamp('synced_at')->nullable()->after('sync_secret');
            $table->string('sync_error', 255)->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->dropColumn(['sync_url', 'sync_secret', 'synced_at', 'sync_error']);
        });
    }
};
