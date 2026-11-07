<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A contact's event history goes with the contact (deletes and GDPR erasure): properties can hold personal data.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contact_events')->whereNotIn('contact_id', DB::table('contacts')->select('id'))->delete();
        Schema::table('contact_events', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_events', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
        });
    }
};
