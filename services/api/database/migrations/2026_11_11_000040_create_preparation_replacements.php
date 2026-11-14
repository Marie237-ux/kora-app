<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An issued unit found damaged or wrong before it leaves with a courier: it goes back to stock set aside
        // (damaged or quarantine), and a replacement unit sold under the same terms is held for the same line.
        Schema::create('order_preparation_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pick_id')->constrained('order_preparation_picks')->restrictOnDelete();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->json('identifiers');
            $t->string('condition', 12);
            $t->foreignUuid('replacement_reservation_id')
                ->unique()
                ->constrained('inventory_reservations')
                ->restrictOnDelete();
            $t->text('note');
            $t->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['order_line_id']);
        });
        $return =
            "NEW.quantity>=1 AND NEW.condition IN ('damaged','quarantine') AND LENGTH(TRIM(NEW.note))>0 AND EXISTS (SELECT 1 FROM order_preparation_picks p WHERE p.id=NEW.pick_id AND p.order_id=NEW.order_id AND p.order_line_id=NEW.order_line_id) AND EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.id=NEW.replacement_reservation_id AND r.order_line_id=NEW.order_line_id AND r.quantity=NEW.quantity)";
        // Picks may now also issue a replacement reservation recorded above.
        $pick =
            "NEW.quantity BETWEEN 1 AND 1000000000 AND NEW.origin IN ('warehouse','receipt','replacement') AND EXISTS (SELECT 1 FROM order_lines l WHERE l.id=NEW.order_line_id AND l.order_id=NEW.order_id) AND EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.id=NEW.reservation_id AND r.order_line_id=NEW.order_line_id AND r.status='consumed' AND r.quantity=NEW.quantity)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION order_preparation_return_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PREPARATION_IMMUTABLE'; END IF; IF NOT COALESCE(({$return}),false) THEN RAISE EXCEPTION 'PREPARATION_RETURN'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER order_preparation_return_guard BEFORE INSERT OR UPDATE OR DELETE ON order_preparation_returns FOR EACH ROW EXECUTE FUNCTION order_preparation_return_guard();
            CREATE OR REPLACE FUNCTION order_preparation_pick_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PREPARATION_IMMUTABLE'; END IF; IF NOT COALESCE(({$pick}),false) THEN RAISE EXCEPTION 'PREPARATION_PICK'; END IF; RETURN NEW; END \$\$;");
        } else {
            DB::unprepared("CREATE TRIGGER order_preparation_return_insert BEFORE INSERT ON order_preparation_returns WHEN NOT COALESCE(({$return}),0) BEGIN SELECT RAISE(ABORT,'PREPARATION_RETURN'); END;
            CREATE TRIGGER order_preparation_return_update BEFORE UPDATE ON order_preparation_returns BEGIN SELECT RAISE(ABORT,'PREPARATION_IMMUTABLE'); END;
            CREATE TRIGGER order_preparation_return_delete BEFORE DELETE ON order_preparation_returns BEGIN SELECT RAISE(ABORT,'PREPARATION_IMMUTABLE'); END;
            DROP TRIGGER order_preparation_pick_insert;
            CREATE TRIGGER order_preparation_pick_insert BEFORE INSERT ON order_preparation_picks WHEN NOT COALESCE(({$pick} AND typeof(NEW.quantity)='integer'),0) BEGIN SELECT RAISE(ABORT,'PREPARATION_PICK'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_preparation_returns');
        $pick =
            "NEW.quantity BETWEEN 1 AND 1000000000 AND NEW.origin IN ('warehouse','receipt') AND EXISTS (SELECT 1 FROM order_lines l WHERE l.id=NEW.order_line_id AND l.order_id=NEW.order_id) AND EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.id=NEW.reservation_id AND r.order_line_id=NEW.order_line_id AND r.status='consumed' AND r.quantity=NEW.quantity)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DROP FUNCTION IF EXISTS order_preparation_return_guard();
            CREATE OR REPLACE FUNCTION order_preparation_pick_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PREPARATION_IMMUTABLE'; END IF; IF NOT COALESCE(({$pick}),false) THEN RAISE EXCEPTION 'PREPARATION_PICK'; END IF; RETURN NEW; END \$\$;");
        } else {
            DB::unprepared("DROP TRIGGER order_preparation_pick_insert;
            CREATE TRIGGER order_preparation_pick_insert BEFORE INSERT ON order_preparation_picks WHEN NOT COALESCE(({$pick} AND typeof(NEW.quantity)='integer'),0) BEGIN SELECT RAISE(ABORT,'PREPARATION_PICK'); END;");
        }
    }
};
