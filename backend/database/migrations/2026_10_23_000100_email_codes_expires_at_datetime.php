<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB gives the first non-null TIMESTAMP column of a table an implicit
 * `ON UPDATE CURRENT_TIMESTAMP`. On email_codes that meant every wrong attempt
 * (which updates `attempts`) reset `expires_at` to now, expiring the code.
 * DATETIME has no implicit behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_codes', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_codes', function (Blueprint $table) {
            $table->timestamp('expires_at')->change();
        });
    }
};
