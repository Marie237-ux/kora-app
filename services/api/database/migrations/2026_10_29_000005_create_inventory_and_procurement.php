<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('command', 100);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'command', 'request_key']);
        });
        Schema::create('business_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
            $t->unsignedInteger('version');
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('private_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('purpose', 40);
            $t->string('disk', 40);
            $t->string('object_key')->unique();
            $t->string('mime', 80);
            $t->unsignedInteger('bytes');
            $t->string('sha256', 64);
            $t->string('scan_status', 20);
            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();
        });
        Schema::create('supply_contracts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->string('reference', 150);
            $t->json('terms');
            $t->json('text');
            $t->string('status', 30)->default('draft');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignUuid('signed_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->json('acceptance')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['supplier_id', 'reference']);
        });
        Schema::create('supply_contract_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('contract_id')->constrained('supply_contracts')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 30);
            $t->json('snapshot');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['contract_id', 'version']);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->string('address', 500);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stock_locations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['warehouse_id', 'code']);
        });
        Schema::create('inventory_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('variant_id')->unique()->constrained()->restrictOnDelete();
            $t->string('tracking_mode', 12);
            $t->boolean('requires_expiry');
            $t->json('logistics');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('contract_id')->constrained('supply_contracts')->restrictOnDelete();
            $t->json('contract_snapshot');
            $t->string('mode', 20);
            $t->string('purpose', 30);
            $t->string('reference', 150)->unique();
            $t->string('status', 30)->default('draft');
            $t->bigInteger('expected_total');
            $t->unsignedInteger('version')->default(1);
            $t->string('supplier_confirmation_reference', 150)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('purchase_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_cost');
            $t->json('commercial_snapshot');
            $t->string('terms_hash', 64);
            $t->string('tracking_mode', 12);
            $t->boolean('requires_expiry');
            $t->unsignedInteger('received_quantity')->default(0);
            $t->unsignedInteger('invoiced_quantity')->default(0);
            $t->timestamps();
            $t->unique(['purchase_order_id', 'offer_id']);
        });
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->timestamp('received_at');
            $t->string('reference', 150);
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['purchase_order_id', 'reference']);
        });
        Schema::create('receipt_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_cost');
            $t->text('variance_reason')->nullable();
            $t->string('quality', 20);
            $t->unsignedInteger('excess_quantity')->default(0);
        });
        Schema::create('stock_lots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('receipt_line_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $t->string('ownership', 12);
            $t->string('lot_number', 100)->nullable();
            $t->date('expires_on')->nullable();
            $t->bigInteger('unit_cost');
            $t->string('terms_hash', 64);
            $t->json('terms_snapshot');
            $t->timestamp('created_at');
        });
        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $t->foreignUuid('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->string('condition', 20);
            $t->unsignedInteger('on_hand')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->timestamps();
            $t->unique(['lot_id', 'location_id', 'condition']);
        });
        Schema::create('inventory_reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->uuid('purpose_id');
            $t->unsignedInteger('quantity');
            $t->string('status', 20);
            $t->timestamp('expires_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('inventory_reservation_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('reservation_id')->constrained('inventory_reservations')->restrictOnDelete();
            $t->foreignUuid('balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unique(['reservation_id', 'balance_id']);
        });
        Schema::create('inventory_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $t->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('balance_id')->nullable()->constrained('inventory_balances')->restrictOnDelete();
            $t->string('identifier_type', 12);
            $t->string('identifier', 100);
            $t->string('status', 20);
            $t->foreignUuid('reservation_id')->nullable()->constrained('inventory_reservations')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['variant_id', 'identifier_type', 'identifier']);
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $t->integer('quantity');
            $t->string('kind', 30);
            $t->string('origin_type', 40);
            $t->uuid('origin_id');
            $t->text('reason');
            $t->json('units');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('supplier_availability', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity')->nullable();
            $t->timestamp('verified_at');
            $t->timestamp('valid_until');
            $t->unsignedInteger('preparation_minutes');
            $t->string('contact', 150);
            $t->string('reference', 150);
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('supplier_reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('offer_id')->constrained()->restrictOnDelete();
            $t->uuid('purpose_id');
            $t->unsignedInteger('quantity');
            $t->string('status', 20);
            $t->timestamp('expires_at');
            $t->string('confirmation_reference', 150)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('purchase_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->string('number', 150);
            $t->date('issued_on');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->bigInteger('amount');
            $t->json('charges');
            $t->string('status', 20)->default('registered');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'number']);
        });
        Schema::create('purchase_invoice_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_payable');
            $t->text('variance_reason')->nullable();
            $t->unique(['invoice_id', 'purchase_line_id']);
        });
        Schema::create('supplier_obligations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->string('source_type', 30);
            $t->uuid('source_id');
            $t->bigInteger('amount');
            $t->json('terms_snapshot');
            $t->string('due_state', 30);
            $t->unsignedInteger('schedule_version')->default(1);
            $t->timestamps();
            $t->unique(['source_type', 'source_id']);
        });
        Schema::create('payable_schedule', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('obligation_id')->constrained('supplier_obligations')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('sequence');
            $t->bigInteger('amount');
            $t->date('due_on');
            $t->text('reason')->nullable();
            $t->foreignUuid('document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['obligation_id', 'version', 'sequence']);
        });
        Schema::create('treasury_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 50)->unique();
            $t->string('name', 150);
            $t->string('kind', 20);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            $t->bigInteger('requested_amount');
            $t->string('request_reference', 150);
            $t->string('status', 20)->default('requested');
            $t->foreignUuid('account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->string('reference', 150)->nullable();
            $t->string('reference_key', 150)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignUuid('evidence_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['account_id', 'reference_key']);
        });
        Schema::create('supplier_payment_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->constrained('supplier_payments')->restrictOnDelete();
            $t->foreignUuid('obligation_id')->constrained('supplier_obligations')->restrictOnDelete();
            $t->bigInteger('amount');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['payment_id', 'obligation_id']);
        });
        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $t->foreignUuid('balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->text('reason');
            $t->string('supplier_reference', 150);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('supplier_credits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('return_id')->nullable()->constrained('supplier_returns')->restrictOnDelete();
            $t->foreignUuid('invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $t->bigInteger('amount');
            $t->string('reference', 150);
            $t->text('reason');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['supplier_id', 'reference']);
        });
        Schema::create('supplier_credit_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('credit_id')->constrained('supplier_credits')->restrictOnDelete();
            $t->foreignUuid('obligation_id')->constrained('supplier_obligations')->restrictOnDelete();
            $t->bigInteger('amount');
            $t->timestamp('created_at');
            $t->unique(['credit_id', 'obligation_id']);
        });
        Schema::create('consignment_sales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $t->foreignUuid('reservation_id')->constrained('inventory_reservations')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_cost');
            $t->uuid('statement_id')->nullable();
            $t->timestamp('sold_at');
            $t->unique(['lot_id', 'reservation_id']);
        });
        Schema::create('consignment_statements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->bigInteger('amount');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->string('reference', 150);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['purchase_order_id', 'reference']);
        });
        Schema::table(
            'consignment_sales',
            fn (Blueprint $t) => $t
                ->foreign('statement_id')
                ->references('id')
                ->on('consignment_statements')
                ->restrictOnDelete(),
        );
        DB::statement("CREATE UNIQUE INDEX unique_imei ON inventory_units (identifier) WHERE identifier_type = 'imei'");
        Schema::table('inventory_reservations', fn (Blueprint $t) => $t->unique(['offer_id', 'purpose_id']));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE inventory_balances ADD CONSTRAINT valid_inventory_balance CHECK (on_hand BETWEEN 0 AND 1000000000 AND reserved BETWEEN 0 AND on_hand)',
            );
            DB::statement(
                'ALTER TABLE purchase_lines ADD CONSTRAINT valid_purchase_quantities CHECK (quantity > 0 AND received_quantity BETWEEN 0 AND 1000000000 AND invoiced_quantity BETWEEN 0 AND quantity AND unit_cost BETWEEN 0 AND 1000000000000)',
            );
            foreach (
                [
                    'supplier_obligations',
                    'supplier_payments',
                    'supplier_payment_allocations',
                    'supplier_credits',
                    'supplier_credit_allocations',
                ] as $table
            ) {
                DB::statement(
                    "ALTER TABLE $table ADD CONSTRAINT valid_".
                        $table.
                        '_amount CHECK (amount > 0 AND amount <= 1000000000000000000)',
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('consignment_sales', fn (Blueprint $t) => $t->dropForeign(['statement_id']));
        foreach (
            [
                'consignment_statements',
                'consignment_sales',
                'supplier_credit_allocations',
                'supplier_credits',
                'supplier_returns',
                'supplier_payment_allocations',
                'supplier_payments',
                'treasury_accounts',
                'payable_schedule',
                'supplier_obligations',
                'purchase_invoice_lines',
                'purchase_invoices',
                'supplier_reservations',
                'supplier_availability',
                'inventory_movements',
                'inventory_units',
                'inventory_reservation_lines',
                'inventory_reservations',
                'inventory_balances',
                'stock_lots',
                'receipt_lines',
                'goods_receipts',
                'purchase_lines',
                'purchase_orders',
                'inventory_profiles',
                'stock_locations',
                'warehouses',
                'supply_contract_revisions',
                'supply_contracts',
                'private_documents',
                'business_settings',
                'command_receipts',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
