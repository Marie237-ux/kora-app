<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // After-sales case opened by the customer on a delivered order line. KORA stays the customer's contact;
        // responsibility is decided on the facts, never split automatically.
        Schema::create('after_sales_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 20);
            $t->unsignedInteger('quantity');
            $t->json('identifiers');
            $t->text('description');
            // Reported within 24 hours of delivery: useful for visible damage, never a loss of rights.
            $t->boolean('reported_quickly');
            $t->string('status', 24)->default('open');
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('resolution', 20)->nullable();
            $t->bigInteger('resolution_amount')->nullable();
            $t->string('responsibility', 16)->nullable();
            $t->text('responsibility_note')->nullable();
            $t->foreignUuid('refund_id')->nullable()->constrained('customer_refunds')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });
        // Conversation: what the customer sees, and internal notes they never see.
        Schema::create('after_sales_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('after_sales_cases')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('visibility', 10);
            $t->text('body')->nullable();
            $t->foreignUuid('document_id')->nullable()->unique()->constrained('private_documents')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['case_id', 'created_at']);
        });
        // Units physically returned: received into a returned/quarantine state, then controlled before any resale.
        Schema::create('after_sales_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('after_sales_cases')->restrictOnDelete();
            $t->foreignUuid('balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->json('identifiers');
            $t->text('note')->nullable();
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('received_at');
            $t->string('control', 12)->default('pending');
            $t->text('control_note')->nullable();
            $t->foreignId('controlled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('controlled_at')->nullable();
            $t->unsignedInteger('version')->default(1);
        });

        $case =
            "NEW.kind IN ('missing','damaged','non_conforming','breakdown','other') AND NEW.quantity>=1 AND LENGTH(TRIM(NEW.description))>0 AND NEW.status IN ('open','in_review','proposed','accepted','declined','resolved','rejected') AND (NEW.resolution IS NULL OR NEW.resolution IN ('exchange','repair','return_refund','refund','other')) AND (NEW.responsibility IS NULL OR NEW.responsibility IN ('supplier','carrier','kora','customer','undetermined')) AND (NEW.status NOT IN ('proposed','accepted','declined','resolved') OR (NEW.resolution IS NOT NULL AND NEW.responsibility IS NOT NULL AND NEW.decided_at IS NOT NULL)) AND (NEW.status NOT IN ('resolved','rejected') OR NEW.closed_at IS NOT NULL) AND (NEW.resolution_amount IS NULL OR NEW.resolution_amount>=1)";
        $caseUpdate =
            "OLD.status NOT IN ('resolved','rejected') AND NEW.version>OLD.version AND NEW.number=OLD.number AND NEW.order_id=OLD.order_id AND NEW.order_line_id=OLD.order_line_id AND NEW.user_id=OLD.user_id AND NEW.kind=OLD.kind AND NEW.quantity=OLD.quantity AND NEW.description=OLD.description AND NEW.reported_quickly=OLD.reported_quickly";
        $message =
            "NEW.visibility IN ('customer','internal') AND (LENGTH(TRIM(COALESCE(NEW.body,'')))>0 OR NEW.document_id IS NOT NULL)";
        $return = "NEW.quantity>=1 AND NEW.control IN ('pending','resellable','defective')";
        $returnUpdate =
            "OLD.control='pending' AND NEW.control IN ('resellable','defective') AND NEW.controlled_by IS NOT NULL AND NEW.controlled_at IS NOT NULL AND LENGTH(TRIM(COALESCE(NEW.control_note,'')))>0 AND NEW.version=OLD.version+1 AND NEW.case_id=OLD.case_id AND NEW.balance_id=OLD.balance_id AND NEW.quantity=OLD.quantity";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION after_sales_case_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'AFTER_SALES_IMMUTABLE'; END IF; IF NOT COALESCE(({$case}),false) THEN RAISE EXCEPTION 'AFTER_SALES_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$caseUpdate}),false) THEN RAISE EXCEPTION 'AFTER_SALES_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER after_sales_case_guard BEFORE INSERT OR UPDATE OR DELETE ON after_sales_cases FOR EACH ROW EXECUTE FUNCTION after_sales_case_guard();
            CREATE OR REPLACE FUNCTION after_sales_message_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'AFTER_SALES_IMMUTABLE'; END IF; IF NOT COALESCE(({$message}),false) THEN RAISE EXCEPTION 'AFTER_SALES_MESSAGE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER after_sales_message_guard BEFORE INSERT OR UPDATE OR DELETE ON after_sales_messages FOR EACH ROW EXECUTE FUNCTION after_sales_message_guard();
            CREATE OR REPLACE FUNCTION after_sales_return_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'AFTER_SALES_IMMUTABLE'; END IF; IF TG_OP='INSERT' THEN IF NOT COALESCE(({$return} AND NEW.control='pending' AND NEW.version=1),false) THEN RAISE EXCEPTION 'AFTER_SALES_RETURN'; END IF; ELSIF NOT COALESCE(({$returnUpdate}),false) THEN RAISE EXCEPTION 'AFTER_SALES_RETURN'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER after_sales_return_guard BEFORE INSERT OR UPDATE OR DELETE ON after_sales_returns FOR EACH ROW EXECUTE FUNCTION after_sales_return_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER after_sales_case_insert BEFORE INSERT ON after_sales_cases WHEN NOT COALESCE(({$case}),0) BEGIN SELECT RAISE(ABORT,'AFTER_SALES_STATE'); END;
            CREATE TRIGGER after_sales_case_update BEFORE UPDATE ON after_sales_cases WHEN NOT COALESCE(({$case} AND {$caseUpdate}),0) BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_case_delete BEFORE DELETE ON after_sales_cases BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_message_insert BEFORE INSERT ON after_sales_messages WHEN NOT COALESCE(({$message}),0) BEGIN SELECT RAISE(ABORT,'AFTER_SALES_MESSAGE'); END;
            CREATE TRIGGER after_sales_message_update BEFORE UPDATE ON after_sales_messages BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_message_delete BEFORE DELETE ON after_sales_messages BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;
            CREATE TRIGGER after_sales_return_insert BEFORE INSERT ON after_sales_returns WHEN NOT COALESCE(({$return} AND NEW.control='pending' AND NEW.version=1),0) BEGIN SELECT RAISE(ABORT,'AFTER_SALES_RETURN'); END;
            CREATE TRIGGER after_sales_return_update BEFORE UPDATE ON after_sales_returns WHEN NOT COALESCE(({$returnUpdate}),0) BEGIN SELECT RAISE(ABORT,'AFTER_SALES_RETURN'); END;
            CREATE TRIGGER after_sales_return_delete BEFORE DELETE ON after_sales_returns BEGIN SELECT RAISE(ABORT,'AFTER_SALES_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('after_sales_returns');
        Schema::dropIfExists('after_sales_messages');
        Schema::dropIfExists('after_sales_cases');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS after_sales_case_guard(); DROP FUNCTION IF EXISTS after_sales_message_guard(); DROP FUNCTION IF EXISTS after_sales_return_guard();',
            );
        }
    }
};
