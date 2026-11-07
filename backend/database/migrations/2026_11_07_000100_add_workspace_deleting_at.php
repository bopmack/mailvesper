<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Set when an owner deletes a workspace: nobody can open it any more, and PurgeWorkspace removes its data in chunks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->timestamp('deleting_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('deleting_at'));
    }
};
