<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $t) {
            // A paid order's stock is held until explicit release/issue, not the unpaid cart timeout.
            $t->foreignUuid('order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
        });
        // Preserve already-paid, still-active warehouse holds when upgrading an existing database.
        DB::statement(
            <<<'SQL'
            UPDATE inventory_reservations
            SET order_line_id = (
                SELECT l.id FROM order_lines l JOIN orders o ON o.id = l.order_id
                WHERE l.reservation_id = inventory_reservations.id AND o.status = 'paid' AND o.closed_at IS NULL
                  AND l.offer_id = inventory_reservations.offer_id AND l.from_warehouse = inventory_reservations.quantity
                LIMIT 1
            )
            WHERE status = 'active' AND EXISTS (
                SELECT 1 FROM order_lines l JOIN orders o ON o.id = l.order_id
                WHERE l.reservation_id = inventory_reservations.id AND o.status = 'paid' AND o.closed_at IS NULL
                  AND l.offer_id = inventory_reservations.offer_id AND l.from_warehouse = inventory_reservations.quantity
            )
            SQL
            ,
        );
        Schema::create('order_purchase_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained('purchase_lines')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('released_quantity')->default(0);
            $t->bigInteger('expected_unit_cost');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['purchase_line_id', 'order_line_id']);
        });
        Schema::create('order_receipt_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_allocation_id')->constrained('order_purchase_allocations')->restrictOnDelete();
            $t->foreignUuid('receipt_line_id')->constrained('receipt_lines')->restrictOnDelete();
            $t->foreignUuid('reservation_id')->unique()->constrained('inventory_reservations')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('actual_unit_cost');
            $t->string('status', 20)->default('held');
            $t->unsignedInteger('version')->default(1);
            $t->text('release_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index('receipt_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_receipt_allocations');
        Schema::dropIfExists('order_purchase_allocations');
        Schema::table('inventory_reservations', fn (Blueprint $t) => $t->dropConstrainedForeignId('order_line_id'));
    }
};
