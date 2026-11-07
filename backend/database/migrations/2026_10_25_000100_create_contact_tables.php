<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->json('fields')->nullable(); // custom fields by key
            $table->string('status', 16)->default('subscribed'); // subscribed | unsubscribed | bounced | complained
            $table->string('source', 16)->default('manual'); // manual | import | api | form
            $table->unsignedTinyInteger('engagement')->nullable(); // 0-100, null until there is enough history
            $table->boolean('dormant')->default(false);
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('last_opened_at')->nullable(); // real opens only
            $table->dateTime('last_clicked_at')->nullable(); // real clicks only
            $table->timestamps();
            $table->unique(['workspace_id', 'email']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'last_opened_at']);
        });

        // Custom field definitions, so imports, segments and merge tags know each field's type.
        Schema::create('contact_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 80);
            $table->string('type', 16)->default('text'); // text | number | date | boolean
            $table->dateTime('created_at')->nullable();
            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('contact_tags', function (Blueprint $table) {
            $table->ulid('workspace_id');
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 60);
            $table->primary(['contact_id', 'tag']);
            $table->index(['workspace_id', 'tag']);
        });

        Schema::create('lists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
        Schema::create('contact_list', function (Blueprint $table) {
            $table->foreignUlid('list_id')->constrained('lists')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->primary(['list_id', 'contact_id']);
            $table->index('contact_id');
        });

        Schema::create('segments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('rules');
            $table->unsignedInteger('cached_count')->nullable();
            $table->dateTime('counted_at')->nullable();
            $table->timestamps();
        });

        // Topics: kinds of email a contact can opt in or out of (preferences instead of all-or-nothing).
        Schema::create('topics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('default_subscribed')->default(true);
            $table->timestamps();
        });
        Schema::create('contact_topic', function (Blueprint $table) {
            $table->foreignUlid('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->boolean('subscribed');
            $table->dateTime('updated_at')->nullable();
            $table->primary(['topic_id', 'contact_id']);
        });

        Schema::create('contact_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename', 255);
            $table->string('path', 255);
            $table->string('status', 16)->default('uploaded'); // uploaded | processing | done | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->json('columns')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->json('stats')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        // Addresses already seen in one import, to count and merge duplicates across chunks. Emptied when the import finishes.
        Schema::create('import_seen', function (Blueprint $table) {
            $table->ulid('import_id');
            $table->char('email_hash', 40);
            $table->primary(['import_id', 'email_hash']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->ulid('topic_id')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('topic_id'));
        foreach (['import_seen', 'contact_imports', 'contact_topic', 'topics', 'segments', 'contact_list', 'lists', 'contact_tags', 'contact_fields', 'contacts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
