<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A plain-text letter can still count opens and clicks: it then goes out with a plain-looking HTML copy. */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->boolean('letter_tracking')->default(false)->after('text_body');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropColumn('letter_tracking');
        });
    }
};
