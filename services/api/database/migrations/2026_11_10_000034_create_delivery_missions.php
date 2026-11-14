<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_profiles', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            // Public identity shown to the customer; identity papers are never stored here.
            $t->string('public_name', 80);
            $t->string('badge', 40)->unique();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('delivery_missions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->string('status', 20)->default('unassigned');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('courier_id')->nullable()->constrained('users')->restrictOnDelete();
            // Who physically holds the parcels: null while they are at the KORA warehouse.
            $t->foreignId('custodian_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('acceptance_required')->default(false);
            $t->unsignedSmallInteger('sequence')->nullable();
            $t->unsignedInteger('assignment')->default(0);
            // Total parcels to hand over: the depot's prepared parcels plus those received at shops on the way.
            $t->unsignedSmallInteger('packages');
            $t->unsignedSmallInteger('depot_packages');
            $t->timestamp('depot_picked_at')->nullable();
            $t->string('code_hash', 64);
            $t->text('code_secret');
            $t->timestamp('code_expires_at');
            $t->unsignedSmallInteger('code_failures')->default(0);
            $t->string('delivery_method', 12)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['courier_id', 'status']);
        });
        Schema::create('delivery_mission_incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('mission_id')->constrained('delivery_missions')->restrictOnDelete();
            $t->string('kind', 24);
            $t->text('note');
            $t->string('status', 12)->default('open');
            $t->string('resolution', 24)->nullable();
            $t->text('resolution_note')->nullable();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['mission_id', 'status']);
        });
        Schema::create('delivery_alternative_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('mission_id')->constrained('delivery_missions')->restrictOnDelete();
            $t->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 24);
            $t->text('note');
            $t->json('control');
            $t->string('status', 12)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['mission_id', 'status']);
        });
        Schema::create('delivery_handover_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('mission_id')->constrained('delivery_missions')->restrictOnDelete();
            $t->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            $t->boolean('accepted');
            $t->timestamp('created_at');
            $t->index(['mission_id', 'created_at']);
        });
        Schema::create('delivery_mission_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('mission_id')->constrained('delivery_missions')->restrictOnDelete();
            $t->string('action', 40);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('data');
            $t->timestamp('created_at');
            $t->index(['mission_id', 'id']);
        });

        // A single live mission per order: a second courier cannot be sent for the same parcels.
        DB::unprepared(
            "CREATE UNIQUE INDEX delivery_missions_one_live ON delivery_missions (order_id) WHERE status NOT IN ('delivered','cancelled')",
        );
        $statuses =
            "'unassigned','assigned','accepted','in_transit','incident','awaiting_approval','delivered','cancelled'";
        $mission = "NEW.status IN ({$statuses}) AND NEW.packages BETWEEN 0 AND 100 AND NEW.depot_packages<=NEW.packages AND (NEW.courier_id IS NOT NULL OR NEW.status IN ('unassigned','cancelled','delivered')) AND (NEW.status<>'delivered' OR (NEW.delivered_at IS NOT NULL AND NEW.delivery_method IN ('code','alternative') AND NEW.custodian_id IS NULL AND NEW.packages>=1)) AND (NEW.status<>'cancelled' OR (NEW.custodian_id IS NULL AND LENGTH(TRIM(COALESCE(NEW.cancel_reason,'')))>0)) AND (NEW.status NOT IN ('in_transit','awaiting_approval') OR NEW.custodian_id=NEW.courier_id)";
        // Delivered and cancelled missions are history: nothing may move them again.
        $transition =
            "OLD.status NOT IN ('delivered','cancelled') AND NEW.version>OLD.version AND NEW.order_id=OLD.order_id AND NEW.number=OLD.number AND NEW.created_by=OLD.created_by AND NEW.code_failures>=0";
        $incident =
            "NEW.kind IN ('customer_absent','address_unreachable','item_missing','damaged','vehicle_unavailable','pickup_discrepancy','other') AND LENGTH(TRIM(NEW.note))>0";
        $resolved =
            "OLD.status='open' AND NEW.status='resolved' AND NEW.resolution IN ('retry','return_to_warehouse','reassigned','cancelled') AND NEW.resolved_by IS NOT NULL AND NEW.resolved_at IS NOT NULL AND NEW.version=OLD.version+1 AND NEW.mission_id=OLD.mission_id AND NEW.kind=OLD.kind AND NEW.note=OLD.note AND NEW.reported_by=OLD.reported_by";
        $alternative =
            "NEW.reason IN ('code_unavailable','code_locked','recipient_delegate','other') AND LENGTH(TRIM(NEW.note))>0";
        $reviewed =
            "OLD.status='pending' AND NEW.status IN ('approved','rejected','withdrawn') AND NEW.mission_id=OLD.mission_id AND NEW.courier_id=OLD.courier_id AND NEW.reason=OLD.reason AND NEW.note=OLD.note AND (NEW.status='withdrawn' OR (NEW.reviewed_by IS NOT NULL AND NEW.reviewed_by<>NEW.courier_id AND LENGTH(TRIM(COALESCE(NEW.review_note,'')))>0))";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION delivery_mission_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'MISSION_IMMUTABLE'; END IF; IF NOT COALESCE(({$mission}),false) THEN RAISE EXCEPTION 'MISSION_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$transition}),false) THEN RAISE EXCEPTION 'MISSION_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER delivery_mission_guard BEFORE INSERT OR UPDATE OR DELETE ON delivery_missions FOR EACH ROW EXECUTE FUNCTION delivery_mission_guard();
            CREATE OR REPLACE FUNCTION delivery_incident_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'MISSION_IMMUTABLE'; END IF; IF TG_OP='INSERT' THEN IF NOT COALESCE(({$incident} AND NEW.status='open' AND NEW.version=1 AND NEW.resolved_by IS NULL),false) THEN RAISE EXCEPTION 'MISSION_INCIDENT'; END IF; ELSIF NOT COALESCE(({$resolved}),false) THEN RAISE EXCEPTION 'MISSION_INCIDENT'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER delivery_incident_guard BEFORE INSERT OR UPDATE OR DELETE ON delivery_mission_incidents FOR EACH ROW EXECUTE FUNCTION delivery_incident_guard();
            CREATE OR REPLACE FUNCTION delivery_alternative_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'MISSION_IMMUTABLE'; END IF; IF TG_OP='INSERT' THEN IF NOT COALESCE(({$alternative} AND NEW.status='pending' AND NEW.reviewed_by IS NULL),false) THEN RAISE EXCEPTION 'MISSION_ALTERNATIVE'; END IF; ELSIF NOT COALESCE(({$reviewed}),false) THEN RAISE EXCEPTION 'MISSION_ALTERNATIVE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER delivery_alternative_guard BEFORE INSERT OR UPDATE OR DELETE ON delivery_alternative_requests FOR EACH ROW EXECUTE FUNCTION delivery_alternative_guard();
            CREATE OR REPLACE FUNCTION delivery_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'MISSION_IMMUTABLE'; END \$\$;
            CREATE TRIGGER delivery_attempts_append_only BEFORE UPDATE OR DELETE ON delivery_handover_attempts FOR EACH ROW EXECUTE FUNCTION delivery_append_only();
            CREATE TRIGGER delivery_events_append_only BEFORE UPDATE OR DELETE ON delivery_mission_events FOR EACH ROW EXECUTE FUNCTION delivery_append_only();");
        } else {
            DB::unprepared("CREATE TRIGGER delivery_mission_insert BEFORE INSERT ON delivery_missions WHEN NOT COALESCE(({$mission}),0) BEGIN SELECT RAISE(ABORT,'MISSION_STATE'); END;
            CREATE TRIGGER delivery_mission_update BEFORE UPDATE ON delivery_missions WHEN NOT COALESCE(({$mission} AND {$transition}),0) BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_mission_delete BEFORE DELETE ON delivery_missions BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_incident_insert BEFORE INSERT ON delivery_mission_incidents WHEN NOT COALESCE(({$incident} AND NEW.status='open' AND NEW.version=1 AND NEW.resolved_by IS NULL),0) BEGIN SELECT RAISE(ABORT,'MISSION_INCIDENT'); END;
            CREATE TRIGGER delivery_incident_update BEFORE UPDATE ON delivery_mission_incidents WHEN NOT COALESCE(({$resolved}),0) BEGIN SELECT RAISE(ABORT,'MISSION_INCIDENT'); END;
            CREATE TRIGGER delivery_incident_delete BEFORE DELETE ON delivery_mission_incidents BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_alternative_insert BEFORE INSERT ON delivery_alternative_requests WHEN NOT COALESCE(({$alternative} AND NEW.status='pending' AND NEW.reviewed_by IS NULL),0) BEGIN SELECT RAISE(ABORT,'MISSION_ALTERNATIVE'); END;
            CREATE TRIGGER delivery_alternative_update BEFORE UPDATE ON delivery_alternative_requests WHEN NOT COALESCE(({$reviewed}),0) BEGIN SELECT RAISE(ABORT,'MISSION_ALTERNATIVE'); END;
            CREATE TRIGGER delivery_alternative_delete BEFORE DELETE ON delivery_alternative_requests BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_attempts_update BEFORE UPDATE ON delivery_handover_attempts BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_attempts_delete BEFORE DELETE ON delivery_handover_attempts BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_events_update BEFORE UPDATE ON delivery_mission_events BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;
            CREATE TRIGGER delivery_events_delete BEFORE DELETE ON delivery_mission_events BEGIN SELECT RAISE(ABORT,'MISSION_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_mission_events');
        Schema::dropIfExists('delivery_handover_attempts');
        Schema::dropIfExists('delivery_alternative_requests');
        Schema::dropIfExists('delivery_mission_incidents');
        Schema::dropIfExists('delivery_missions');
        Schema::dropIfExists('courier_profiles');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS delivery_mission_guard(); DROP FUNCTION IF EXISTS delivery_incident_guard(); DROP FUNCTION IF EXISTS delivery_alternative_guard(); DROP FUNCTION IF EXISTS delivery_append_only();',
            );
        }
    }
};
