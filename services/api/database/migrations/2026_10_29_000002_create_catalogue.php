<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->json('private_details')->nullable();
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->string('slug')->unique();
            $t->string('name');
            $t->json('description');
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable();
            $t->string('slug')->unique();
            $t->json('name');
            $t->timestamps();
        });
        Schema::table(
            'categories',
            fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete(),
        );
        Schema::create('products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('category_id')->constrained()->restrictOnDelete();
            $t->string('slug')->unique();
            $t->json('name');
            $t->json('description');
            $t->string('status')->default('draft');
            $t->timestamps();
        });
        Schema::create('variants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->string('sku')->unique();
            $t->json('attributes');
            $t->timestamps();
        });
        Schema::create('offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('shop_id')->constrained()->restrictOnDelete();
            $t->bigInteger('reference_price');
            $t->bigInteger('selling_price');
            $t->string('status')->default('draft');
            $t->string('availability')->default('unconfirmed');
            $t->json('warranty');
            $t->string('image_path')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'shop_id']);
        });
        Schema::create('purchase_agreements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('minimum_quantity')->default(1);
            $t->unsignedInteger('discount_basis_points');
            $t->unsignedInteger('priority')->default(0);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->string('contract_reference');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->string('resource_id');
            $t->json('details');
            $t->uuid('request_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('deduplication_key')->unique();
            $t->string('type');
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE offers ADD CONSTRAINT nonnegative_prices CHECK (reference_price >= 0 AND selling_price >= 0 AND reference_price <= 1000000000000 AND selling_price <= 1000000000000)',
            );
            DB::statement(
                'ALTER TABLE purchase_agreements ADD CONSTRAINT valid_agreement CHECK (discount_basis_points BETWEEN 0 AND 10000 AND minimum_quantity >= 1 AND (ends_at IS NULL OR ends_at > starts_at))',
            );
        }
    }

    public function down(): void
    {
        foreach (
            [
                'outbox_events',
                'audit_events',
                'purchase_agreements',
                'offers',
                'variants',
                'products',
                'categories',
                'shops',
                'suppliers',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
