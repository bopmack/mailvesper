<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A campaign can be a plain-text letter: text only, no tracking, the opt-out in the words (it reaches Gmail's Primary tab). */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->string('format', 10)->default('design')->after('doc');
            $t->longText('text_body')->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropColumn(['format', 'text_body']);
        });
    }
};
