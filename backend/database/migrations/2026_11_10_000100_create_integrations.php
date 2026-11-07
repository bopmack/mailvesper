<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stripe and Shopify webhooks into contact events, and the product state behind "price drop" and
     * "back in stock" alerts.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);              // stripe | shopify
            $table->string('token', 40)->unique();       // in the webhook URL
            $table->text('secret')->nullable();          // encrypted: Stripe's whsec_…, Shopify's app secret
            $table->string('shop_domain', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('events_received')->default(0);
            $table->dateTime('last_event_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'provider']);
        });
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('integration_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 120);
            $table->dateTime('created_at');
            $table->unique(['integration_id', 'event_id']); // providers retry: each event is handled once
            $table->index('created_at');
        });
        Schema::create('product_states', function (Blueprint $table) {
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('product_id', 100);
            $table->decimal('price', 14, 2)->nullable();
            $table->boolean('in_stock')->nullable();
            $table->string('name', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('image', 500)->nullable();
            $table->dateTime('updated_at');
            $table->primary(['workspace_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_states');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integrations');
    }
};
