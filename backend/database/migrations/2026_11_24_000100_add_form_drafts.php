<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Forms get a draft and a live version. The form's own columns stay what the public page, the embed and sign-ups
// use (the live version); `draft` holds edits not published yet, and `published_at` is null until the form first
// goes live. Forms that exist already were live, so they count as published and keep working exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->json('draft')->nullable()->after('display');
            $table->dateTime('published_at')->nullable()->after('enabled');
        });
        DB::table('forms')->update(['published_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['draft', 'published_at']);
        });
    }
};
