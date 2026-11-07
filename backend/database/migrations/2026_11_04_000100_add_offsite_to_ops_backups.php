<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Each backup's off-site copy (S3-compatible storage): where it went, or why it didn't.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_backups', function (Blueprint $table) {
            $table->string('offsite_status', 10)->nullable();
            $table->string('offsite_key', 500)->nullable();
            $table->string('offsite_error', 500)->nullable();
            $table->dateTime('offsite_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ops_backups', fn (Blueprint $t) => $t->dropColumn(['offsite_status', 'offsite_key', 'offsite_error', 'offsite_at']));
    }
};
