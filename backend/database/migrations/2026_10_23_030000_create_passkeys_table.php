<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('credential_id', 512)->unique(); // base64url
            $table->text('public_key'); // PEM
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->string('aaguid', 64)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
