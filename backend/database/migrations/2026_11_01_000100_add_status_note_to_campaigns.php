<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('status_note', 255)->nullable()->after('status'); // why it's paused (e.g. the plan's email limit)
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('status_note'));
    }
};
