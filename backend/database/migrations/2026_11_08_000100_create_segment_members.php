<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who is in a segment, for the "joined / left a segment" journey triggers. Only segments a live journey watches
     * are tracked; `tracked_hash` is the rules the membership was computed with (new rules = a new baseline).
     */
    public function up(): void
    {
        Schema::create('segment_members', function (Blueprint $table) {
            $table->foreignUlid('segment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->dateTime('joined_at');
            $table->primary(['segment_id', 'contact_id']);
            $table->index('contact_id');
        });
        Schema::table('segments', function (Blueprint $table) {
            $table->string('tracked_hash', 64)->nullable()->after('counted_at');
            $table->dateTime('tracked_at')->nullable()->after('tracked_hash');
        });
    }

    public function down(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            $table->dropColumn(['tracked_hash', 'tracked_at']);
        });
        Schema::dropIfExists('segment_members');
    }
};
