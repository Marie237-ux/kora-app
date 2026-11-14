<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A courier's day: the ordered pickups and deliveries of their missions. Stop completion is never stored
        // here; it is read from the missions themselves, so the plan cannot claim a delivery that did not happen.
        Schema::create('delivery_rounds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            $t->date('date');
            $t->string('status', 12)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['date', 'status']);
        });
        Schema::create('delivery_round_stops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('round_id')->constrained('delivery_rounds')->restrictOnDelete();
            // A delivery mission (pickup, delivery), a depot collection (collect, drop), or a direct shop collection
            // received on the way of a mission (collect with both the mission and the collection).
            $t->foreignUuid('mission_id')->nullable()->constrained('delivery_missions')->restrictOnDelete();
            $t->foreignUuid('collection_id')->nullable()->constrained('supplier_collections')->restrictOnDelete();
            $t->string('kind', 10);
            // KORA warehouse of a pickup or drop; null for a pickup from another courier who holds the parcels.
            $t->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->string('origin', 10);
            $t->timestamps();
            $t->index(['round_id', 'sequence']);
            $t->index(['mission_id']);
            $t->index(['collection_id']);
        });
        DB::unprepared(
            "CREATE UNIQUE INDEX delivery_rounds_one_open ON delivery_rounds (courier_id, date) WHERE status='open'",
        );
        $round =
            "NEW.status IN ('open','cancelled') AND (NEW.status<>'cancelled' OR LENGTH(TRIM(COALESCE(NEW.cancel_reason,'')))>0)";
        $transition =
            "OLD.status='open' AND NEW.version>OLD.version AND NEW.courier_id=OLD.courier_id AND NEW.date=OLD.date AND NEW.number=OLD.number";
        $stop =
            "((NEW.kind IN ('pickup','delivery') AND NEW.mission_id IS NOT NULL AND NEW.collection_id IS NULL) OR (NEW.kind='collect' AND NEW.collection_id IS NOT NULL) OR (NEW.kind='drop' AND NEW.collection_id IS NOT NULL AND NEW.mission_id IS NULL)) AND NEW.origin IN ('suggested','manual') AND NEW.sequence>=1 AND (NEW.kind IN ('pickup','drop') OR NEW.warehouse_id IS NULL) AND (NEW.kind<>'drop' OR NEW.warehouse_id IS NOT NULL) AND EXISTS (SELECT 1 FROM delivery_rounds r WHERE r.id=NEW.round_id AND r.status='open')";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION delivery_round_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'ROUND_IMMUTABLE'; END IF; IF NOT COALESCE(({$round}),false) THEN RAISE EXCEPTION 'ROUND_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$transition}),false) THEN RAISE EXCEPTION 'ROUND_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER delivery_round_guard BEFORE INSERT OR UPDATE OR DELETE ON delivery_rounds FOR EACH ROW EXECUTE FUNCTION delivery_round_guard();
            CREATE OR REPLACE FUNCTION delivery_round_stop_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN IF NOT EXISTS (SELECT 1 FROM delivery_rounds r WHERE r.id=OLD.round_id AND r.status='open') THEN RAISE EXCEPTION 'ROUND_IMMUTABLE'; END IF; RETURN OLD; END IF; IF NOT COALESCE(({$stop}),false) THEN RAISE EXCEPTION 'ROUND_STOP'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER delivery_round_stop_guard BEFORE INSERT OR UPDATE OR DELETE ON delivery_round_stops FOR EACH ROW EXECUTE FUNCTION delivery_round_stop_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER delivery_round_insert BEFORE INSERT ON delivery_rounds WHEN NOT COALESCE(({$round}),0) BEGIN SELECT RAISE(ABORT,'ROUND_STATE'); END;
            CREATE TRIGGER delivery_round_update BEFORE UPDATE ON delivery_rounds WHEN NOT COALESCE(({$round} AND {$transition}),0) BEGIN SELECT RAISE(ABORT,'ROUND_IMMUTABLE'); END;
            CREATE TRIGGER delivery_round_delete BEFORE DELETE ON delivery_rounds BEGIN SELECT RAISE(ABORT,'ROUND_IMMUTABLE'); END;
            CREATE TRIGGER delivery_round_stop_insert BEFORE INSERT ON delivery_round_stops WHEN NOT COALESCE(({$stop}),0) BEGIN SELECT RAISE(ABORT,'ROUND_STOP'); END;
            CREATE TRIGGER delivery_round_stop_update BEFORE UPDATE ON delivery_round_stops WHEN NOT COALESCE(({$stop}),0) BEGIN SELECT RAISE(ABORT,'ROUND_STOP'); END;
            CREATE TRIGGER delivery_round_stop_delete BEFORE DELETE ON delivery_round_stops WHEN NOT EXISTS (SELECT 1 FROM delivery_rounds r WHERE r.id=OLD.round_id AND r.status='open') BEGIN SELECT RAISE(ABORT,'ROUND_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_round_stops');
        Schema::dropIfExists('delivery_rounds');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS delivery_round_guard(); DROP FUNCTION IF EXISTS delivery_round_stop_guard();',
            );
        }
    }
};
