<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_reservations', function (Blueprint $t) {
            $t->foreignUuid('order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            $t->unsignedInteger('confirmed_quantity')->nullable();
        });
        Schema::table('order_lines', function (Blueprint $t) {
            $t->foreignUuid('supplier_reservation_id')
                ->nullable()
                ->constrained('supplier_reservations')
                ->restrictOnDelete();
            $t->unsignedInteger('supplier_confirmed_quantity')->default(0);
            $t->unsignedInteger('cancelled_quantity')->default(0);
            $t->unsignedInteger('supply_version')->default(1);
        });
        Schema::create('order_supply_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->unsignedInteger('missing_quantity');
            $t->json('message');
            $t->string('status', 30)->default('awaiting_choice');
            $t->string('choice', 30)->nullable();
            $t->foreignUuid('refund_id')->nullable()->unique()->constrained('customer_refunds')->restrictOnDelete();
            $t->bigInteger('refund_amount')->nullable();
            $t->bigInteger('delivery_refund')->default(0);
            $t->timestamp('chosen_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['order_line_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_supply_cases');
        Schema::table('order_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supplier_reservation_id');
            $t->dropColumn(['supplier_confirmed_quantity', 'cancelled_quantity', 'supply_version']);
        });
        Schema::table('supplier_reservations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('order_line_id');
            $t->dropColumn('confirmed_quantity');
        });
    }
};
