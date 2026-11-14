<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purchase units fetched at a supplier shop. Either carried to a KORA depot, where the stock team receives
        // them; or, when they are not in a KORA depot, received at the shop for a customer's order (by the courier,
        // or by a designated manager who goes along) with a photo of the shop's slip and the serial numbers, then
        // taken straight to the customer by that order's delivery mission.
        Schema::create('supplier_collections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->string('destination', 10)->default('warehouse');
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $t->foreignUuid('point_id')->constrained('collection_points')->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->foreignUuid('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            // "On the road" stock location the direct units are received into, then issued from to the order.
            $t->foreignUuid('transit_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('unassigned');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('courier_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('custodian_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('acceptance_required')->default(false);
            $t->unsignedSmallInteger('packages')->nullable();
            $t->boolean('shortfall')->default(false);
            $t->string('incident_kind', 24)->nullable();
            $t->text('incident_note')->nullable();
            $t->timestamp('collected_at')->nullable();
            $t->timestamp('dropped_at')->nullable();
            $t->foreignUuid('receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $t->foreignUuid('document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['courier_id', 'status']);
            $t->index(['order_id', 'status']);
        });
        Schema::create('supplier_collection_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('collection_id')->constrained('supplier_collections')->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained('purchase_lines')->restrictOnDelete();
            // Direct collections only: the customer line and purchase allocation the units are bought for.
            $t->foreignUuid('order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            // Checked by the line guard rather than a foreign key, so the earlier allocation migration can still be
            // rebuilt in place by its upgrade test.
            $t->uuid('allocation_id')->nullable()->index();
            $t->unsignedInteger('expected_quantity');
            $t->unsignedInteger('collected_quantity')->nullable();
            $t->json('identifiers')->nullable();
            $t->unique(['collection_id', 'purchase_line_id', 'allocation_id']);
        });
        Schema::create('supplier_collection_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('collection_id')->constrained('supplier_collections')->restrictOnDelete();
            $t->string('action', 40);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('data');
            $t->timestamp('created_at');
            $t->index(['collection_id', 'id']);
        });

        $statuses = "'unassigned','assigned','accepted','collected','incident','dropped','received','cancelled'";
        $destination =
            "((NEW.destination='warehouse' AND NEW.warehouse_id IS NOT NULL AND NEW.order_id IS NULL AND NEW.transit_location_id IS NULL AND NEW.supervisor_id IS NULL) OR (NEW.destination='customer' AND NEW.order_id IS NOT NULL AND NEW.transit_location_id IS NOT NULL AND NEW.warehouse_id IS NULL AND NEW.status IN ('unassigned','received','cancelled') AND NEW.custodian_id IS NULL))";
        $collection = "NEW.status IN ({$statuses}) AND {$destination} AND (NEW.courier_id IS NOT NULL OR NEW.status IN ('unassigned','cancelled','dropped','received')) AND (NEW.status<>'collected' OR (NEW.custodian_id=NEW.courier_id AND NEW.collected_at IS NOT NULL AND NEW.packages BETWEEN 1 AND 100)) AND (NEW.status<>'dropped' OR (NEW.custodian_id IS NULL AND NEW.dropped_at IS NOT NULL AND NEW.collected_at IS NOT NULL)) AND (NEW.status<>'received' OR (NEW.receipt_id IS NOT NULL AND NEW.collected_at IS NOT NULL AND (NEW.destination='warehouse' AND NEW.dropped_at IS NOT NULL OR NEW.destination='customer' AND NEW.document_id IS NOT NULL AND NEW.received_by IS NOT NULL AND NEW.packages BETWEEN 1 AND 100))) AND (NEW.status<>'cancelled' OR (NEW.custodian_id IS NULL AND NEW.collected_at IS NULL AND LENGTH(TRIM(COALESCE(NEW.cancel_reason,'')))>0))";
        $transition =
            "OLD.status NOT IN ('received','cancelled') AND NEW.version>OLD.version AND NEW.number=OLD.number AND NEW.destination=OLD.destination AND NEW.purchase_order_id=OLD.purchase_order_id AND NEW.shop_id=OLD.shop_id AND ((NEW.warehouse_id IS NULL AND OLD.warehouse_id IS NULL) OR NEW.warehouse_id=OLD.warehouse_id) AND ((NEW.order_id IS NULL AND OLD.order_id IS NULL) OR NEW.order_id=OLD.order_id) AND NEW.created_by=OLD.created_by";
        // Counted quantities never exceed what was expected, and expected quantities never change.
        $line =
            'NEW.expected_quantity>=1 AND (NEW.collected_quantity IS NULL OR NEW.collected_quantity<=NEW.expected_quantity) AND (NEW.allocation_id IS NULL OR EXISTS (SELECT 1 FROM order_purchase_allocations a WHERE a.id=NEW.allocation_id AND a.order_line_id=NEW.order_line_id AND a.purchase_line_id=NEW.purchase_line_id))';
        $lineUpdate =
            'OLD.collected_quantity IS NULL AND NEW.expected_quantity=OLD.expected_quantity AND NEW.collection_id=OLD.collection_id AND NEW.purchase_line_id=OLD.purchase_line_id';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION supplier_collection_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'COLLECTION_IMMUTABLE'; END IF; IF NOT COALESCE(({$collection}),false) THEN RAISE EXCEPTION 'COLLECTION_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$transition}),false) THEN RAISE EXCEPTION 'COLLECTION_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER supplier_collection_guard BEFORE INSERT OR UPDATE OR DELETE ON supplier_collections FOR EACH ROW EXECUTE FUNCTION supplier_collection_guard();
            CREATE OR REPLACE FUNCTION supplier_collection_line_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'COLLECTION_IMMUTABLE'; END IF; IF NOT COALESCE(({$line}),false) THEN RAISE EXCEPTION 'COLLECTION_LINE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$lineUpdate}),false) THEN RAISE EXCEPTION 'COLLECTION_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER supplier_collection_line_guard BEFORE INSERT OR UPDATE OR DELETE ON supplier_collection_lines FOR EACH ROW EXECUTE FUNCTION supplier_collection_line_guard();
            CREATE OR REPLACE FUNCTION supplier_collection_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'COLLECTION_IMMUTABLE'; END \$\$;
            CREATE TRIGGER supplier_collection_events_append_only BEFORE UPDATE OR DELETE ON supplier_collection_events FOR EACH ROW EXECUTE FUNCTION supplier_collection_append_only();");
        } else {
            DB::unprepared("CREATE TRIGGER supplier_collection_insert BEFORE INSERT ON supplier_collections WHEN NOT COALESCE(({$collection}),0) BEGIN SELECT RAISE(ABORT,'COLLECTION_STATE'); END;
            CREATE TRIGGER supplier_collection_update BEFORE UPDATE ON supplier_collections WHEN NOT COALESCE(({$collection} AND {$transition}),0) BEGIN SELECT RAISE(ABORT,'COLLECTION_IMMUTABLE'); END;
            CREATE TRIGGER supplier_collection_delete BEFORE DELETE ON supplier_collections BEGIN SELECT RAISE(ABORT,'COLLECTION_IMMUTABLE'); END;
            CREATE TRIGGER supplier_collection_line_insert BEFORE INSERT ON supplier_collection_lines WHEN NOT COALESCE(({$line}),0) BEGIN SELECT RAISE(ABORT,'COLLECTION_LINE'); END;
            CREATE TRIGGER supplier_collection_line_update BEFORE UPDATE ON supplier_collection_lines WHEN NOT COALESCE(({$line} AND {$lineUpdate}),0) BEGIN SELECT RAISE(ABORT,'COLLECTION_IMMUTABLE'); END;
            CREATE TRIGGER supplier_collection_line_delete BEFORE DELETE ON supplier_collection_lines BEGIN SELECT RAISE(ABORT,'COLLECTION_IMMUTABLE'); END;
            CREATE TRIGGER supplier_collection_events_update BEFORE UPDATE ON supplier_collection_events BEGIN SELECT RAISE(ABORT,'COLLECTION_IMMUTABLE'); END;
            CREATE TRIGGER supplier_collection_events_delete BEFORE DELETE ON supplier_collection_events BEGIN SELECT RAISE(ABORT,'COLLECTION_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_collection_events');
        Schema::dropIfExists('supplier_collection_lines');
        Schema::dropIfExists('supplier_collections');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS supplier_collection_guard(); DROP FUNCTION IF EXISTS supplier_collection_line_guard(); DROP FUNCTION IF EXISTS supplier_collection_append_only();',
            );
        }
    }
};
