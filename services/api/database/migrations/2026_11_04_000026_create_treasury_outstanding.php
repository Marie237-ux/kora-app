<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_outstanding', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('statement_id')->constrained('treasury_statements')->restrictOnDelete();
            $t->foreignUuid('statement_line_id')
                ->nullable()
                ->constrained('treasury_statement_lines')
                ->restrictOnDelete();
            $t->foreignId('ledger_line_id')->nullable()->constrained('accounting_entry_lines')->restrictOnDelete();
            $t->date('expected_on');
            $t->text('note');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 10)->default('pending');
            $t->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['statement_id', 'statement_line_id', 'active_slot'], 'treasury_outstanding_row_unique');
            $t->unique(['statement_id', 'ledger_line_id', 'active_slot'], 'treasury_outstanding_ledger_unique');
        });
        Schema::create('treasury_closures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('statement_id')->constrained('treasury_statements')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['statement_id', 'version']);
        });
        $invalidInsert =
            "(SELECT status FROM treasury_statements WHERE id=NEW.statement_id) <> 'open' OR NEW.status <> 'pending' OR NEW.active_slot IS NULL OR NEW.active_slot <> 1 OR NEW.reviewed_by IS NOT NULL OR NEW.reviewed_at IS NOT NULL OR ((NEW.statement_line_id IS NULL) = (NEW.ledger_line_id IS NULL))";
        $invalidUpdate =
            "(SELECT status FROM treasury_statements WHERE id=OLD.statement_id) <> 'open' OR NOT COALESCE(((OLD.status='pending' AND NEW.status='approved' AND NEW.active_slot=1 AND OLD.reviewed_by IS NULL AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at IS NOT NULL) OR (OLD.status IN ('pending','approved') AND NEW.status='void' AND NEW.active_slot IS NULL AND NEW.reviewed_by IS NOT DISTINCT FROM OLD.reviewed_by AND NEW.reviewed_at IS NOT DISTINCT FROM OLD.reviewed_at)), false)";
        $invalidClosure =
            "(SELECT status FROM treasury_statements WHERE id=NEW.statement_id) <> 'open' OR NEW.version <> (SELECT version+1 FROM treasury_statements WHERE id=NEW.statement_id)";
        if (DB::getDriverName() === 'pgsql') {
            // TG_ARGV is null when no mutable columns were supplied. Subtracting null
            // would make the comparison unknown and silently accept content changes.
            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION treasury_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'TREASURY_IMMUTABLE'; END IF;
                  IF (to_jsonb(OLD) - COALESCE(TG_ARGV, ARRAY[]::text[])) <> (to_jsonb(NEW) - COALESCE(TG_ARGV, ARRAY[]::text[]))
                    THEN RAISE EXCEPTION 'TREASURY_IMMUTABLE'; END IF;
                  RETURN NEW;
                END $$;
                SQL
                ,
            );
            DB::unprepared("CREATE OR REPLACE FUNCTION treasury_outstanding_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='INSERT' THEN IF {$invalidInsert} THEN RAISE EXCEPTION 'TREASURY_OUTSTANDING_STATE'; END IF; ELSE IF {$invalidUpdate} THEN RAISE EXCEPTION 'TREASURY_OUTSTANDING_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER treasury_outstanding_state BEFORE INSERT OR UPDATE ON treasury_outstanding FOR EACH ROW EXECUTE FUNCTION treasury_outstanding_state();
                CREATE TRIGGER treasury_outstanding_history BEFORE UPDATE OR DELETE ON treasury_outstanding FOR EACH ROW EXECUTE FUNCTION treasury_history_guard('status','active_slot','reviewed_by','reviewed_at');
                CREATE TRIGGER treasury_closures_history BEFORE UPDATE OR DELETE ON treasury_closures FOR EACH ROW EXECUTE FUNCTION treasury_history_guard();
                CREATE OR REPLACE FUNCTION treasury_closure_insert() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$invalidClosure} THEN RAISE EXCEPTION 'TREASURY_CLOSURE_STATE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER treasury_closure_insert BEFORE INSERT ON treasury_closures FOR EACH ROW EXECUTE FUNCTION treasury_closure_insert();");
        } else {
            // SQLite's IS is null-safe, just like PostgreSQL's IS NOT DISTINCT FROM.
            $invalidUpdate = str_replace('IS NOT DISTINCT FROM', 'IS', $invalidUpdate);
            DB::unprepared("CREATE TRIGGER treasury_outstanding_insert BEFORE INSERT ON treasury_outstanding WHEN {$invalidInsert} BEGIN SELECT RAISE(ABORT,'TREASURY_OUTSTANDING_STATE'); END;
                CREATE TRIGGER treasury_outstanding_state BEFORE UPDATE ON treasury_outstanding WHEN {$invalidUpdate} BEGIN SELECT RAISE(ABORT,'TREASURY_OUTSTANDING_STATE'); END;
                CREATE TRIGGER treasury_closure_insert BEFORE INSERT ON treasury_closures WHEN {$invalidClosure} BEGIN SELECT RAISE(ABORT,'TREASURY_CLOSURE_STATE'); END;");
            foreach (
                [
                    'treasury_outstanding' => ['status', 'active_slot', 'reviewed_by', 'reviewed_at'],
                    'treasury_closures' => [],
                ] as $table => $mutable
            ) {
                $fields = implode(',', array_diff(Schema::getColumnListing($table), $mutable));
                DB::unprepared("CREATE TRIGGER {$table}_history_update BEFORE UPDATE OF {$fields} ON {$table} BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END;
                    CREATE TRIGGER {$table}_history_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END;");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_closures');
        Schema::dropIfExists('treasury_outstanding');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS treasury_outstanding_state(); DROP FUNCTION IF EXISTS treasury_closure_insert();',
            );
        }
    }
};
