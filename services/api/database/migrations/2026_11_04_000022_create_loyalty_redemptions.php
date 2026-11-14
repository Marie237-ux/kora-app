<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_redemption_policy', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('enabled')->default(false);
            $t->json('rules')->nullable();
            // Exact cumulative value, including exceptional late payments beyond the configured budget.
            $t->string('used_budget', 40)->default('0');
            $t->timestamps();
        });
        DB::table('loyalty_redemption_policy')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('loyalty_redemption_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('version')->unique();
            $t->boolean('enabled');
            $t->json('rules');
            $t->string('sha256', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_checkout_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $t->foreignUuid('cart_id')->constrained('carts')->restrictOnDelete();
            $t->foreignUuid('delivery_quote_id')->constrained('delivery_quotes')->restrictOnDelete();
            $t->unsignedInteger('cart_version');
            $t->unsignedInteger('delivery_version');
            $t->unsignedInteger('policy_version');
            $t->unsignedBigInteger('points');
            $t->unsignedBigInteger('discount');
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->timestamp('expires_at')->index();
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_redemptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('quote_id')->unique()->constrained('loyalty_checkout_quotes')->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $t->string('status', 30)->default('held');
            $t->unsignedBigInteger('points');
            $t->unsignedBigInteger('discount');
            $t->unsignedBigInteger('consumed_points')->default(0);
            $t->unsignedBigInteger('restored_points')->default(0);
            $t->json('rules_snapshot');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['account_id', 'status', 'expires_at']);
        });
        Schema::create('loyalty_redemption_allocations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('redemption_id')->constrained('loyalty_redemptions')->restrictOnDelete();
            $t->foreignUuid('grant_id')->constrained('loyalty_grants')->restrictOnDelete();
            $t->unsignedBigInteger('points');
            $t->string('status', 20);
            $t->timestamp('created_at');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignUuid('loyalty_checkout_quote_id')
                ->nullable()
                ->unique()
                ->constrained('loyalty_checkout_quotes')
                ->restrictOnDelete();
            $t->unsignedBigInteger('loyalty_discount')->default(0);
        });
        Schema::table('order_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('loyalty_discount')->default(0);
            $t->unsignedBigInteger('loyalty_points_used')->default(0);
        });
        Schema::table('loyalty_movements', function (Blueprint $t) {
            $t->bigInteger('reserved_delta')->default(0);
            $t->foreignUuid('redemption_id')->nullable()->constrained('loyalty_redemptions')->restrictOnDelete();
        });
        Schema::table('loyalty_grants', function (Blueprint $t) {
            $t->foreignUuid('redemption_id')->nullable()->constrained('loyalty_redemptions')->restrictOnDelete();
        });
        Schema::table('loyalty_refund_lines', function (Blueprint $t) {
            $t->string('status', 20)->default('paid');
            $t->unsignedInteger('start_quantity')->nullable();
            $t->unsignedBigInteger('discount')->default(0);
            $t->unsignedBigInteger('restored_points')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_grants', fn (Blueprint $t) => $t->dropConstrainedForeignId('redemption_id'));
        Schema::table('loyalty_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('redemption_id');
            $t->dropColumn('reserved_delta');
        });
        Schema::table(
            'loyalty_refund_lines',
            fn (Blueprint $t) => $t->dropColumn(['status', 'start_quantity', 'discount', 'restored_points']),
        );
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn(['loyalty_discount', 'loyalty_points_used']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('loyalty_checkout_quote_id');
            $t->dropColumn('loyalty_discount');
        });
        Schema::dropIfExists('loyalty_redemption_allocations');
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_checkout_quotes');
        Schema::dropIfExists('loyalty_redemption_revisions');
        Schema::dropIfExists('loyalty_redemption_policy');
    }
};
