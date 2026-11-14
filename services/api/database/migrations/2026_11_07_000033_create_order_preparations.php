<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // Synthesis of the picks and anomalies below; never a substitute for the line quantities.
            $t->string('preparation_status', 20)->default('not_started');
            $t->unsignedInteger('preparation_version')->default(1);
            $t->unsignedSmallInteger('preparation_packages')->nullable();
            $t->timestamp('prepared_at')->nullable();
            $t->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['preparation_status', 'paid_at']);
        });
        Schema::create('order_preparation_picks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            // One physical issue per retained reservation: a replay cannot take the same units twice.
            $t->foreignUuid('reservation_id')->unique()->constrained('inventory_reservations')->restrictOnDelete();
            $t->string('origin', 12);
            $t->unsignedInteger('quantity');
            $t->json('identifiers');
            $t->json('sources');
            $t->text('note')->nullable();
            $t->foreignId('picked_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('picked_at');
            $t->index(['order_id', 'order_line_id']);
        });
        Schema::create('order_preparation_anomalies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $t->string('kind', 20);
            $t->unsignedInteger('quantity');
            $t->text('note');
            $t->string('status', 12)->default('open');
            $t->text('resolution_note')->nullable();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['order_id', 'status']);
        });
        $pick =
            "NEW.quantity BETWEEN 1 AND 1000000000 AND NEW.origin IN ('warehouse','receipt') AND EXISTS (SELECT 1 FROM order_lines l WHERE l.id=NEW.order_line_id AND l.order_id=NEW.order_id) AND EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.id=NEW.reservation_id AND r.order_line_id=NEW.order_line_id AND r.status='consumed' AND r.quantity=NEW.quantity)";
        $anomaly =
            "NEW.quantity>=1 AND NEW.kind IN ('missing','damaged','expired','mismatch','other') AND NEW.status IN ('open','resolved') AND LENGTH(TRIM(NEW.note))>0 AND EXISTS (SELECT 1 FROM order_lines l WHERE l.id=NEW.order_line_id AND l.order_id=NEW.order_id)";
        $resolved =
            "OLD.status='open' AND NEW.status='resolved' AND NEW.version=OLD.version+1 AND NEW.resolved_by IS NOT NULL AND NEW.resolved_at IS NOT NULL AND LENGTH(TRIM(NEW.resolution_note))>0 AND NEW.order_id=OLD.order_id AND NEW.order_line_id=OLD.order_line_id AND NEW.kind=OLD.kind AND NEW.quantity=OLD.quantity AND NEW.note=OLD.note AND NEW.reported_by=OLD.reported_by";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION order_preparation_pick_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PREPARATION_IMMUTABLE'; END IF; IF NOT COALESCE(({$pick}),false) THEN RAISE EXCEPTION 'PREPARATION_PICK'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER order_preparation_pick_guard BEFORE INSERT OR UPDATE OR DELETE ON order_preparation_picks FOR EACH ROW EXECUTE FUNCTION order_preparation_pick_guard();
            CREATE OR REPLACE FUNCTION order_preparation_anomaly_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'PREPARATION_IMMUTABLE'; END IF; IF TG_OP='INSERT' THEN IF NOT COALESCE(({$anomaly} AND NEW.status='open' AND NEW.version=1 AND NEW.resolved_by IS NULL),false) THEN RAISE EXCEPTION 'PREPARATION_ANOMALY'; END IF; ELSIF NOT COALESCE(({$resolved}),false) THEN RAISE EXCEPTION 'PREPARATION_ANOMALY'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER order_preparation_anomaly_guard BEFORE INSERT OR UPDATE OR DELETE ON order_preparation_anomalies FOR EACH ROW EXECUTE FUNCTION order_preparation_anomaly_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER order_preparation_pick_insert BEFORE INSERT ON order_preparation_picks WHEN NOT COALESCE(({$pick} AND typeof(NEW.quantity)='integer'),false) BEGIN SELECT RAISE(ABORT,'PREPARATION_PICK'); END;
            CREATE TRIGGER order_preparation_pick_update BEFORE UPDATE ON order_preparation_picks BEGIN SELECT RAISE(ABORT,'PREPARATION_IMMUTABLE'); END;
            CREATE TRIGGER order_preparation_pick_delete BEFORE DELETE ON order_preparation_picks BEGIN SELECT RAISE(ABORT,'PREPARATION_IMMUTABLE'); END;
            CREATE TRIGGER order_preparation_anomaly_insert BEFORE INSERT ON order_preparation_anomalies WHEN NOT COALESCE(({$anomaly} AND NEW.status='open' AND NEW.version=1 AND NEW.resolved_by IS NULL),false) BEGIN SELECT RAISE(ABORT,'PREPARATION_ANOMALY'); END;
            CREATE TRIGGER order_preparation_anomaly_update BEFORE UPDATE ON order_preparation_anomalies WHEN NOT COALESCE(({$resolved}),false) BEGIN SELECT RAISE(ABORT,'PREPARATION_ANOMALY'); END;
            CREATE TRIGGER order_preparation_anomaly_delete BEFORE DELETE ON order_preparation_anomalies BEGIN SELECT RAISE(ABORT,'PREPARATION_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_preparation_anomalies');
        Schema::dropIfExists('order_preparation_picks');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS order_preparation_pick_guard(); DROP FUNCTION IF EXISTS order_preparation_anomaly_guard();',
            );
        }
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['preparation_status', 'paid_at']);
            $t->dropConstrainedForeignId('prepared_by');
            $t->dropColumn(['preparation_status', 'preparation_version', 'preparation_packages', 'prepared_at']);
        });
    }
};
