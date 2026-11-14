<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->unsignedBigInteger('last_number');
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('cart_id')->constrained()->restrictOnDelete();
            // One delivery quote can produce at most one order.
            $t->foreignUuid('quote_id')->unique()->constrained('delivery_quotes')->restrictOnDelete();
            $t->string('status', 30);
            $t->bigInteger('items_total');
            $t->bigInteger('delivery_total');
            $t->bigInteger('total');
            $t->string('currency', 3);
            $t->string('locale', 5);
            $t->json('address_snapshot');
            $t->json('delivery_snapshot');
            $t->timestamp('payment_expires_at');
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
            $t->index(['status', 'payment_expires_at']);
        });
        Schema::create('order_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->uuid('cart_line_id')->nullable();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_price');
            $t->bigInteger('line_total');
            // What the customer bought: shop, names, SKU, variant, warranty. Never rewritten.
            $t->json('snapshot');
            // Reference price and supplier agreement in force: internal views only.
            $t->json('internal_snapshot');
            $t->unsignedInteger('from_warehouse');
            $t->unsignedInteger('from_supplier');
            $t->foreignUuid('reservation_id')->nullable()->constrained('inventory_reservations')->restrictOnDelete();
            $t->string('supply_status', 30);
            $t->timestamps();
            $t->index('order_id');
        });
        Schema::create('payment_attempts', function (Blueprint $t) {
            // The attempt id is the payment reference sent to the provider.
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('provider', 30);
            $t->string('status', 30);
            $t->bigInteger('amount');
            $t->string('currency', 3);
            $t->text('payment_url')->nullable();
            $t->string('provider_transaction_id', 120)->nullable();
            $t->string('provider_status', 40)->nullable();
            $t->boolean('testmode')->nullable();
            $t->string('failure_reason', 80)->nullable();
            $t->unsignedInteger('checks')->default(0);
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['order_id', 'request_key']);
            $t->index(['status', 'updated_at']);
            $t->index(['provider', 'provider_transaction_id']);
        });
        Schema::create('payment_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 30);
            $t->string('transaction_id', 120)->nullable();
            $t->uuid('attempt_id')->nullable();
            $t->boolean('signature_valid');
            // Hash of the received fields: proves what arrived without storing phone numbers or names.
            $t->string('fields_hash', 64);
            $t->string('outcome', 40)->nullable();
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->index(['provider', 'transaction_id']);
        });
        Schema::create('payment_incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('attempt_id')->nullable()->constrained('payment_attempts')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            $t->string('kind', 40);
            $t->string('status', 20)->default('open');
            $t->json('details');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'kind']);
            $t->unique(['attempt_id', 'kind']);
            $t->unique(['order_line_id', 'kind']);
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->string('action', 60);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('data');
            $t->timestamp('created_at');
            $t->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (
            [
                'order_events',
                'payment_incidents',
                'payment_notifications',
                'payment_attempts',
                'order_lines',
                'orders',
                'document_sequences',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
