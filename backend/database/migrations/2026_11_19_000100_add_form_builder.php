<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The visual form builder: a form's look (colours, font, image, layout, steps) and how it shows on a site
// (inline, popup, slide-in or bar, with display rules). Fields now carry a type, options, width and step.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->json('design')->nullable()->after('fields');
            $table->json('display')->nullable()->after('design');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['design', 'display']);
        });
    }
};
