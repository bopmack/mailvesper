<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data between steps, as in n8n: each step's output is kept on the run (later steps use it as {{ steps.<id>.… }})
     * and on the step row (the run's debug view shows what came in and went out). The editor's pinned test input
     * lives on the journey.
     */
    public function up(): void
    {
        Schema::table('journey_runs', function (Blueprint $table) {
            $table->json('data')->nullable()->after('context');
        });
        Schema::table('journey_steps', function (Blueprint $table) {
            $table->json('output')->nullable()->after('detail');
            $table->unsignedInteger('ms')->nullable()->after('output');
        });
        Schema::table('journeys', function (Blueprint $table) {
            $table->json('test_data')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('journeys', fn (Blueprint $table) => $table->dropColumn('test_data'));
        Schema::table('journey_steps', fn (Blueprint $table) => $table->dropColumn(['output', 'ms']));
        Schema::table('journey_runs', fn (Blueprint $table) => $table->dropColumn('data'));
    }
};
