<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->json('name');
            // draft → active ⇄ paused → ended. Only an active offer within its dates applies.
            $t->string('status', 20)->default('draft');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->json('zone_ids');
            // At least one selected line must have a unit price at or above this amount (price actually paid).
            $t->bigInteger('min_unit_price');
            $t->unsignedInteger('uses_per_customer');
            // full: the whole delivery quote; base: only its base fee, supplements stay due and are shown.
            $t->string('coverage', 10);
            $t->bigInteger('budget');
            $t->string('funded_by', 20);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'starts_at']);
        });
        Schema::create('delivery_offer_usages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_id')->constrained('delivery_offers')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            // One advantage per order, whatever the number of physical deliveries.
            $t->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            // reserved (during payment) → consumed (payment confirmed) or released (expired, cancelled).
            $t->string('status', 20);
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['offer_id', 'status']);
            $t->index(['user_id', 'offer_id', 'status']);
        });
        // Eligibility shown with the quote; re-checked and reserved under lock when the order is created.
        Schema::table('delivery_quotes', function (Blueprint $t) {
            $t->json('promotion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_quotes', fn (Blueprint $t) => $t->dropColumn('promotion'));
        Schema::dropIfExists('delivery_offer_usages');
        Schema::dropIfExists('delivery_offers');
    }
};
