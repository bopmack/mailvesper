<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SES notices are kept first and applied afterwards by a background job, so the webhook answers Amazon at once
     * even while the server is busy sending. Rows already in the table were applied when they arrived.
     */
    public function up(): void
    {
        Schema::table('ses_events', function (Blueprint $t) {
            $t->dateTime('processed_at')->nullable()->after('created_at');
            $t->unsignedTinyInteger('attempts')->default(0)->after('processed_at');
            $t->dateTime('retry_at')->nullable()->after('attempts');
            $t->string('error', 255)->nullable()->after('retry_at');
            $t->index(['processed_at', 'id']);
        });
        DB::table('ses_events')->whereNull('processed_at')->update(['processed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('ses_events', function (Blueprint $t) {
            $t->dropIndex(['processed_at', 'id']);
            $t->dropColumn(['processed_at', 'attempts', 'retry_at', 'error']);
        });
    }
};
