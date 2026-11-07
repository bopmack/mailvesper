<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Platform admins can suspend an account (no sign-in) or pause a workspace's sending (e.g. after abuse flags).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dateTime('suspended_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['suspended_at', 'suspended_reason']));
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn(['suspended_at', 'suspended_reason']));
    }
};
