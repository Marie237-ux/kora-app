<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->json('name');
            // Display names of the districts (quartiers) served by the zone; matched after normalisation.
            $t->json('districts');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('collection_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('zone_id')->constrained('delivery_zones')->restrictOnDelete();
            // Exactly one of shop_id / warehouse_id is set: a partner pick-up point or a KORA depot.
            $t->foreignUuid('shop_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('address', 500);
            $t->unsignedSmallInteger('priority')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['shop_id', 'active', 'priority']);
            $t->index(['warehouse_id', 'active', 'priority']);
        });
        Schema::create('delivery_grids', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('number')->unique();
            $t->string('status', 20)->default('draft');
            $t->json('rules');
            $t->text('notes');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'published_at']);
        });
        Schema::create('delivery_slot_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Null zone: the rule applies to every served zone.
            $t->foreignUuid('zone_id')->nullable()->constrained('delivery_zones')->restrictOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->string('starts_at', 5);
            $t->string('ends_at', 5);
            $t->unsignedInteger('capacity');
            $t->unsignedInteger('cutoff_minutes');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['weekday', 'active']);
        });
        Schema::create('delivery_slot_closures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->date('date');
            // Null rule: the whole day is affected.
            $t->foreignUuid('rule_id')->nullable()->constrained('delivery_slot_rules')->restrictOnDelete();
            $t->unsignedInteger('capacity');
            $t->string('reason', 300);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['date', 'rule_id']);
        });
        // One row per slot occurrence, used only as a lock so that two quotes cannot take the last place.
        Schema::create('delivery_slot_locks', function (Blueprint $t) {
            $t->foreignUuid('rule_id')->constrained('delivery_slot_rules')->restrictOnDelete();
            $t->date('date');
            $t->timestamp('created_at');
            $t->primary(['rule_id', 'date']);
        });
        Schema::create('customer_addresses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('label', 80);
            $t->string('district', 120);
            $t->string('landmark', 300);
            $t->text('instructions');
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->string('contact_name', 150);
            $t->string('contact_phone', 30);
            $t->string('alternate_name', 150)->nullable();
            $t->string('alternate_phone', 30)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'archived_at']);
        });
        Schema::create('delivery_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('cart_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('cart_version');
            $t->foreignUuid('address_id')->constrained('customer_addresses')->restrictOnDelete();
            $t->json('address_snapshot');
            $t->foreignUuid('zone_id')->nullable()->constrained('delivery_zones')->restrictOnDelete();
            $t->foreignUuid('grid_id')->nullable()->constrained('delivery_grids')->restrictOnDelete();
            $t->string('status', 30);
            $t->json('reasons');
            $t->json('plan');
            $t->json('breakdown');
            $t->bigInteger('total')->nullable();
            $t->boolean('demo_amounts');
            $t->unsignedInteger('ready_after_minutes')->nullable();
            $t->foreignUuid('slot_rule_id')->nullable()->constrained('delivery_slot_rules')->restrictOnDelete();
            $t->date('slot_date')->nullable();
            $t->string('slot_status', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
            $t->index(['slot_rule_id', 'slot_date', 'slot_status']);
        });
    }

    public function down(): void
    {
        foreach (
            [
                'delivery_quotes',
                'customer_addresses',
                'delivery_slot_locks',
                'delivery_slot_closures',
                'delivery_slot_rules',
                'delivery_grids',
                'collection_points',
                'delivery_zones',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
