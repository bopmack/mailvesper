<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The emailed code is stored as a password hash; argon2id hashes are ~97 characters, more than the original 64.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sender_identities', fn (Blueprint $t) => $t->string('token', 255)->change());
    }

    public function down(): void
    {
        Schema::table('sender_identities', fn (Blueprint $t) => $t->string('token', 64)->change());
    }
};
