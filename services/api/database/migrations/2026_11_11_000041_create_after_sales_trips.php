<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // After-sales courier trips: fetch returned items at the customer's address and drop them at a depot, or
        // take an exchange item (already issued from stock for the case) from the depot to the customer, who
        // confirms with their own one-time code.
        Schema::create('after_sales_trips', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('case_id')->constrained('after_sales_cases')->restrictOnDelete();
            $t->string('direction', 10);
            $t->string('status', 12)->default('unassigned');
            $t->unsignedInteger('version')->default(1);
            $t->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('courier_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('custodian_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->json('identifiers');
            $t->unsignedSmallInteger('packages')->nullable();
            $t->foreignUuid('reservation_id')->nullable()->constrained('inventory_reservations')->restrictOnDelete();
            $t->string('code_hash', 64)->nullable();
            $t->text('code_secret')->nullable();
            $t->timestamp('code_expires_at')->nullable();
            $t->unsignedSmallInteger('code_failures')->default(0);
            $t->timestamp('picked_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['courier_id', 'status']);
        });
        Schema::create('after_sales_trip_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('trip_id')->constrained('after_sales_trips')->restrictOnDelete();
            $t->string('action', 40);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('data');
            $t->timestamp('created_at');
            $t->index(['trip_id', 'id']);
        });
        DB::unprepared(
            "CREATE UNIQUE INDEX after_sales_trips_one_live ON after_sales_trips (case_id, direction) WHERE status NOT IN ('completed','cancelled')",
        );
        Schema::table('delivery_round_stops', function (Blueprint $t) {
            $t->foreignUuid('trip_id')->nullable()->constrained('after_sales_trips')->restrictOnDelete();
        });

        $trip =
            "NEW.direction IN ('pickup','delivery') AND NEW.status IN ('unassigned','accepted','in_transit','completed','cancelled') AND NEW.quantity>=1 AND (NEW.courier_id IS NOT NULL OR NEW.status IN ('unassigned','cancelled')) AND (NEW.status<>'in_transit' OR (NEW.custodian_id=NEW.courier_id AND NEW.picked_at IS NOT NULL AND NEW.packages BETWEEN 1 AND 100)) AND (NEW.status<>'completed' OR (NEW.custodian_id IS NULL AND NEW.completed_at IS NOT NULL)) AND (NEW.status<>'cancelled' OR (NEW.custodian_id IS NULL AND NEW.picked_at IS NULL AND LENGTH(TRIM(COALESCE(NEW.cancel_reason,'')))>0)) AND (NEW.direction<>'delivery' OR (NEW.reservation_id IS NOT NULL AND NEW.code_hash IS NOT NULL AND NEW.packages BETWEEN 1 AND 100))";
        $transition =
            "OLD.status NOT IN ('completed','cancelled') AND NEW.version>OLD.version AND NEW.number=OLD.number AND NEW.case_id=OLD.case_id AND NEW.direction=OLD.direction AND NEW.created_by=OLD.created_by";
        $stop =
            "((NEW.kind IN ('pickup','delivery') AND ((NEW.mission_id IS NOT NULL AND NEW.trip_id IS NULL) OR (NEW.trip_id IS NOT NULL AND NEW.mission_id IS NULL)) AND NEW.collection_id IS NULL) OR (NEW.kind='collect' AND NEW.collection_id IS NOT NULL AND NEW.trip_id IS NULL) OR (NEW.kind='drop' AND NEW.collection_id IS NOT NULL AND NEW.mission_id IS NULL AND NEW.trip_id IS NULL)) AND NEW.origin IN ('suggested','manual') AND NEW.sequence>=1 AND (NEW.kind IN ('pickup','drop','delivery') OR NEW.warehouse_id IS NULL) AND (NEW.kind<>'drop' OR NEW.warehouse_id IS NOT NULL) AND EXISTS (SELECT 1 FROM delivery_rounds r WHERE r.id=NEW.round_id AND r.status='open')";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION after_sales_trip_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END IF; IF NOT COALESCE(({$trip}),false) THEN RAISE EXCEPTION 'TRIP_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$transition}),false) THEN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER after_sales_trip_guard BEFORE INSERT OR UPDATE OR DELETE ON after_sales_trips FOR EACH ROW EXECUTE FUNCTION after_sales_trip_guard();
            CREATE OR REPLACE FUNCTION after_sales_trip_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'TRIP_IMMUTABLE'; END \$\$;
            CREATE TRIGGER after_sales_trip_events_append_only BEFORE UPDATE OR DELETE ON after_sales_trip_events FOR EACH ROW EXECUTE FUNCTION after_sales_trip_append_only();
            CREATE OR REPLACE FUNCTION delivery_round_stop_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN IF NOT EXISTS (SELECT 1 FROM delivery_rounds r WHERE r.id=OLD.round_id AND r.status='open') THEN RAISE EXCEPTION 'ROUND_IMMUTABLE'; END IF; RETURN OLD; END IF; IF NOT COALESCE(({$stop}),false) THEN RAISE EXCEPTION 'ROUND_STOP'; END IF; RETURN NEW; END \$\$;");
        } else {
            DB::unprepared("CREATE TRIGGER after_sales_trip_insert BEFORE INSERT ON after_sales_trips WHEN NOT COALESCE(({$trip}),0) BEGIN SELECT RAISE(ABORT,'TRIP_STATE'); END;
            CREATE TRIGGER after_sales_trip_update BEFORE UPDATE ON after_sales_trips WHEN NOT COALESCE(({$trip} AND {$transition}),0) BEGIN SELECT RAISE(ABORT,'TRIP_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_trip_delete BEFORE DELETE ON after_sales_trips BEGIN SELECT RAISE(ABORT,'TRIP_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_trip_events_update BEFORE UPDATE ON after_sales_trip_events BEGIN SELECT RAISE(ABORT,'TRIP_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_trip_events_delete BEFORE DELETE ON after_sales_trip_events BEGIN SELECT RAISE(ABORT,'TRIP_IMMUTABLE'); END;
            DROP TRIGGER IF EXISTS delivery_round_stop_insert;
            DROP TRIGGER IF EXISTS delivery_round_stop_update;
            DROP TRIGGER IF EXISTS delivery_round_stop_delete;
            CREATE TRIGGER delivery_round_stop_delete BEFORE DELETE ON delivery_round_stops WHEN NOT EXISTS (SELECT 1 FROM delivery_rounds r WHERE r.id=OLD.round_id AND r.status='open') BEGIN SELECT RAISE(ABORT,'ROUND_IMMUTABLE'); END;
            CREATE TRIGGER delivery_round_stop_insert BEFORE INSERT ON delivery_round_stops WHEN NOT COALESCE(({$stop}),0) BEGIN SELECT RAISE(ABORT,'ROUND_STOP'); END;
            CREATE TRIGGER delivery_round_stop_update BEFORE UPDATE ON delivery_round_stops WHEN NOT COALESCE(({$stop}),0) BEGIN SELECT RAISE(ABORT,'ROUND_STOP'); END;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS delivery_round_stop_insert; DROP TRIGGER IF EXISTS delivery_round_stop_update;',
            );
        }
        Schema::table('delivery_round_stops', fn (Blueprint $t) => $t->dropConstrainedForeignId('trip_id'));
        Schema::dropIfExists('after_sales_trip_events');
        Schema::dropIfExists('after_sales_trips');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS after_sales_trip_guard(); DROP FUNCTION IF EXISTS after_sales_trip_append_only();',
            );
        }
    }
};
