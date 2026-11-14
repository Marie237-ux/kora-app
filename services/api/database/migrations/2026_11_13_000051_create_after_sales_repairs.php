<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer's own unit while it is repaired: where it is, who repairs it, what came out of it.
        Schema::create('after_sales_repairs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('case_id')->constrained('after_sales_cases')->restrictOnDelete();
            $t->foreignUuid('return_id')->unique()->constrained('after_sales_returns')->restrictOnDelete();
            // Current stock balance of the unit (quarantine at the depot, or the repairer's location).
            $t->foreignUuid('balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->json('identifiers');
            $t->string('repairer_kind', 12);
            $t->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->string('repairer_name', 150)->nullable();
            $t->string('status', 12)->default('open');
            $t->string('outcome', 16)->nullable();
            $t->text('outcome_note')->nullable();
            $t->string('repairer_reference', 150)->nullable();
            $t->foreignUuid('sent_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->foreignUuid('back_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->unsignedBigInteger('cost')->nullable();
            $t->string('cost_bearer', 10)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('back_at')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['supplier_id', 'status']);
        });
        // A defective returned unit sent back to its supplier, and the supplier's answer on the warranty.
        Schema::create('after_sales_supplier_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('after_sales_cases')->restrictOnDelete();
            $t->foreignUuid('return_id')->constrained('after_sales_returns')->restrictOnDelete();
            $t->foreignUuid('supplier_return_id')->unique()->constrained('supplier_returns')->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('decision', 12)->default('pending');
            $t->text('decision_note')->nullable();
            $t->foreignUuid('decision_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['supplier_id', 'decision']);
        });
        Schema::table('after_sales_trips', function (Blueprint $t) {
            // Set when the delivery carries the customer's repaired unit back (no replacement, no reservation).
            $t->uuid('repair_id')->nullable()->index();
        });

        // Same trip rule as before, except that a delivery carries either a replacement (reservation) or the
        // customer's repaired unit (repair), never neither.
        $trip =
            "NEW.direction IN ('pickup','delivery') AND NEW.status IN ('unassigned','accepted','in_transit','completed','cancelled') AND NEW.quantity>=1 AND (NEW.courier_id IS NOT NULL OR NEW.status IN ('unassigned','cancelled')) AND (NEW.status<>'in_transit' OR (NEW.custodian_id=NEW.courier_id AND NEW.picked_at IS NOT NULL AND NEW.packages BETWEEN 1 AND 100)) AND (NEW.status<>'completed' OR (NEW.custodian_id IS NULL AND NEW.completed_at IS NOT NULL)) AND (NEW.status<>'cancelled' OR (NEW.custodian_id IS NULL AND NEW.picked_at IS NULL AND LENGTH(TRIM(COALESCE(NEW.cancel_reason,'')))>0)) AND (NEW.direction<>'delivery' OR ((NEW.reservation_id IS NOT NULL OR NEW.repair_id IS NOT NULL) AND NEW.code_hash IS NOT NULL AND NEW.packages BETWEEN 1 AND 100))";
        $transition =
            "OLD.status NOT IN ('completed','cancelled') AND NEW.version>OLD.version AND NEW.number=OLD.number AND NEW.case_id=OLD.case_id AND NEW.direction=OLD.direction AND NEW.created_by=OLD.created_by AND ((NEW.repair_id IS NULL AND OLD.repair_id IS NULL) OR NEW.repair_id=OLD.repair_id)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                "CREATE OR REPLACE FUNCTION after_sales_trip_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END IF; IF NOT COALESCE(({$trip}),false) THEN RAISE EXCEPTION 'TRIP_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$transition}),false) THEN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END IF; RETURN NEW; END \$\$;",
            );
        } else {
            DB::unprepared("DROP TRIGGER IF EXISTS after_sales_trip_insert;
            DROP TRIGGER IF EXISTS after_sales_trip_update;
            CREATE TRIGGER after_sales_trip_insert BEFORE INSERT ON after_sales_trips WHEN NOT COALESCE(({$trip}),0) BEGIN SELECT RAISE(ABORT,'TRIP_STATE'); END;
            CREATE TRIGGER after_sales_trip_update BEFORE UPDATE ON after_sales_trips WHEN NOT COALESCE(({$trip} AND {$transition}),0) BEGIN SELECT RAISE(ABORT,'TRIP_IMMUTABLE'); END;");
        }

        $repair =
            "NEW.repairer_kind IN ('supplier','third_party','internal') AND (NEW.repairer_kind<>'supplier' OR NEW.supplier_id IS NOT NULL) AND (NEW.repairer_kind<>'third_party' OR NEW.repairer_name IS NOT NULL) AND NEW.status IN ('open','at_repairer','back','returning','returned') AND NEW.quantity>=1 AND (NEW.status<>'at_repairer' OR (NEW.sent_at IS NOT NULL AND NEW.sent_document_id IS NOT NULL)) AND (NEW.status NOT IN ('back','returning','returned') OR (NEW.back_at IS NOT NULL AND NEW.outcome IN ('repaired','not_repairable'))) AND (NEW.status<>'returned' OR NEW.returned_at IS NOT NULL) AND (NEW.cost_bearer IS NULL OR NEW.cost_bearer IN ('supplier','kora','customer'))";
        $repairUpdate =
            "OLD.status<>'returned' AND NEW.case_id=OLD.case_id AND NEW.return_id=OLD.return_id AND NEW.quantity=OLD.quantity AND NEW.version>OLD.version AND (OLD.outcome IS NULL OR NEW.outcome=OLD.outcome)";
        $supplierReturn =
            "NEW.decision IN ('pending','credit','replacement','repair','refused') AND (NEW.decision='pending' OR (NEW.decided_by IS NOT NULL AND NEW.decided_at IS NOT NULL AND LENGTH(TRIM(COALESCE(NEW.decision_note,'')))>0))";
        $supplierReturnUpdate =
            "OLD.decision='pending' AND NEW.supplier_return_id=OLD.supplier_return_id AND NEW.case_id=OLD.case_id AND NEW.version>OLD.version";
        foreach (
            [
                'after_sales_repairs' => [$repair, $repairUpdate, 'AFTER_SALES_REPAIR'],
                'after_sales_supplier_returns' => [
                    $supplierReturn,
                    $supplierReturnUpdate,
                    'AFTER_SALES_SUPPLIER_RETURN',
                ],
            ] as $table => [$insert, $update, $code]
        ) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'AFTER_SALES_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION '{$code}'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'AFTER_SALES_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard();");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'{$code}'); END;
                CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;
                CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;");
            }
        }
    }

    public function down(): void
    {
        // Restores the earlier trip rule (a delivery always carries a reservation) before the column goes.
        $trip =
            "NEW.direction IN ('pickup','delivery') AND NEW.status IN ('unassigned','accepted','in_transit','completed','cancelled') AND NEW.quantity>=1 AND (NEW.courier_id IS NOT NULL OR NEW.status IN ('unassigned','cancelled')) AND (NEW.status<>'in_transit' OR (NEW.custodian_id=NEW.courier_id AND NEW.picked_at IS NOT NULL AND NEW.packages BETWEEN 1 AND 100)) AND (NEW.status<>'completed' OR (NEW.custodian_id IS NULL AND NEW.completed_at IS NOT NULL)) AND (NEW.status<>'cancelled' OR (NEW.custodian_id IS NULL AND NEW.picked_at IS NULL AND LENGTH(TRIM(COALESCE(NEW.cancel_reason,'')))>0)) AND (NEW.direction<>'delivery' OR (NEW.reservation_id IS NOT NULL AND NEW.code_hash IS NOT NULL AND NEW.packages BETWEEN 1 AND 100))";
        $transition =
            "OLD.status NOT IN ('completed','cancelled') AND NEW.version>OLD.version AND NEW.number=OLD.number AND NEW.case_id=OLD.case_id AND NEW.direction=OLD.direction AND NEW.created_by=OLD.created_by";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                "CREATE OR REPLACE FUNCTION after_sales_trip_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END IF; IF NOT COALESCE(({$trip}),false) THEN RAISE EXCEPTION 'TRIP_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$transition}),false) THEN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END IF; RETURN NEW; END \$\$;",
            );
        } else {
            DB::unprepared("DROP TRIGGER IF EXISTS after_sales_trip_insert;
            DROP TRIGGER IF EXISTS after_sales_trip_update;
            CREATE TRIGGER after_sales_trip_insert BEFORE INSERT ON after_sales_trips WHEN NOT COALESCE(({$trip}),0) BEGIN SELECT RAISE(ABORT,'TRIP_STATE'); END;
            CREATE TRIGGER after_sales_trip_update BEFORE UPDATE ON after_sales_trips WHEN NOT COALESCE(({$trip} AND {$transition}),0) BEGIN SELECT RAISE(ABORT,'TRIP_IMMUTABLE'); END;");
        }
        Schema::table('after_sales_trips', fn (Blueprint $t) => $t->dropColumn('repair_id'));
        Schema::dropIfExists('after_sales_supplier_returns');
        Schema::dropIfExists('after_sales_repairs');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS after_sales_repairs_guard(); DROP FUNCTION IF EXISTS after_sales_supplier_returns_guard();',
            );
        }
    }
};
