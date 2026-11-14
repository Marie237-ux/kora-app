<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_receipts', fn (Blueprint $t) => $t->json('input_snapshot')->nullable());
        Schema::table(
            'inventory_movements',
            fn (Blueprint $t) => $t
                ->foreignUuid('document_id')
                ->nullable()
                ->constrained('private_documents')
                ->restrictOnDelete(),
        );
        Schema::table(
            'purchase_lines',
            fn (Blueprint $t) => $t->unsignedInteger('approved_overage_quantity')->default(0),
        );
        Schema::create('receipt_overage_approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('receipt_line_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_cost');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->text('reason');
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::table('purchase_invoices', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        Schema::create('purchase_invoice_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 40);
            $t->json('snapshot');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['invoice_id', 'version']);
        });
        DB::table('purchase_invoices')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('purchase_invoice_revisions')->insert([
                        'invoice_id' => $row->id,
                        'version' => 1,
                        'action' => 'migration_snapshot',
                        'snapshot' => json_encode(
                            [
                                'invoice' => $row,
                                'lines' => DB::table('purchase_invoice_lines')->where('invoice_id', $row->id)->get(),
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                        'actor_id' => null,
                        'created_at' => now(),
                    ]);
                }
            });
        Schema::table('supplier_credit_allocations', function (Blueprint $t) {
            $t->dropUnique(['credit_id', 'obligation_id']);
            $t->index(['credit_id', 'obligation_id']);
        });
        Schema::table('supplier_obligations', fn (Blueprint $t) => $t->index(['supplier_id', 'created_at']));
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->index(['supplier_id', 'status']));
        Schema::table('stock_lots', fn (Blueprint $t) => $t->index('offer_id'));
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->index(['balance_id', 'created_at']));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchase_lines DROP CONSTRAINT valid_purchase_quantities');
            DB::statement(
                'ALTER TABLE purchase_lines ADD CONSTRAINT valid_purchase_quantities CHECK (quantity > 0 AND approved_overage_quantity BETWEEN 0 AND 1000000000 AND received_quantity BETWEEN 0 AND 1000000000 AND invoiced_quantity BETWEEN 0 AND quantity + approved_overage_quantity AND unit_cost BETWEEN 0 AND 1000000000000)',
            );
            DB::statement(
                "ALTER TABLE inventory_units ADD CONSTRAINT valid_unit_location CHECK ((status IN ('on_hand', 'reserved') AND balance_id IS NOT NULL) OR (status IN ('consumed', 'missing', 'returned_to_supplier') AND balance_id IS NULL))",
            );
            DB::statement(
                "ALTER TABLE inventory_units ADD CONSTRAINT valid_reserved_unit CHECK (status <> 'reserved' OR reservation_id IS NOT NULL)",
            );
            DB::statement(
                "ALTER TABLE inventory_units ADD CONSTRAINT valid_identifier CHECK (identifier_type = 'serial' OR (identifier_type = 'imei' AND identifier ~ '^[0-9]{15}$'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['valid_unit_location', 'valid_reserved_unit', 'valid_identifier'] as $name) {
                DB::statement("ALTER TABLE inventory_units DROP CONSTRAINT $name");
            }
            DB::statement('ALTER TABLE purchase_lines DROP CONSTRAINT valid_purchase_quantities');
            DB::statement(
                'ALTER TABLE purchase_lines ADD CONSTRAINT valid_purchase_quantities CHECK (quantity > 0 AND received_quantity BETWEEN 0 AND 1000000000 AND invoiced_quantity BETWEEN 0 AND quantity AND unit_cost BETWEEN 0 AND 1000000000000)',
            );
        }
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropIndex(['balance_id', 'created_at']));
        Schema::table('stock_lots', fn (Blueprint $t) => $t->dropIndex(['offer_id']));
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->dropIndex(['supplier_id', 'status']));
        Schema::table('supplier_obligations', fn (Blueprint $t) => $t->dropIndex(['supplier_id', 'created_at']));
        Schema::table('supplier_credit_allocations', function (Blueprint $t) {
            $t->dropIndex(['credit_id', 'obligation_id']);
            $t->unique(['credit_id', 'obligation_id']);
        });
        Schema::dropIfExists('purchase_invoice_revisions');
        Schema::table('purchase_invoices', fn (Blueprint $t) => $t->dropColumn('version'));
        Schema::dropIfExists('receipt_overage_approvals');
        Schema::table('purchase_lines', fn (Blueprint $t) => $t->dropColumn('approved_overage_quantity'));
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('document_id'));
        Schema::table('command_receipts', fn (Blueprint $t) => $t->dropColumn('input_snapshot'));
    }
};
