<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ready-made segments from a connected site ("Verified creators", "Not logged in for 2 days"…): grouped under the
// site's name in pickers, and keyed so connecting again only adds the ones that are missing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            $table->string('group', 120)->nullable()->after('name');
            $table->string('preset_key', 80)->nullable()->after('group');
            $table->unique(['workspace_id', 'preset_key']);
        });
    }

    public function down(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'preset_key']);
            $table->dropColumn(['group', 'preset_key']);
        });
    }
};
