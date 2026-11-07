<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved designs: the builder document plus the HTML the shared renderer made from it.
        Schema::create('email_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('subject', 255)->nullable();
            $table->string('category', 40)->nullable();
            $table->longText('doc');
            $table->longText('html');
            $table->decimal('size_kb', 8, 1)->default(0);
            $table->json('checks')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'updated_at']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('path', 255);
            $table->string('url', 500);
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('brand')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('brand'));
        Schema::dropIfExists('media');
        Schema::dropIfExists('email_templates');
    }
};
