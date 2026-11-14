<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_coverage_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->foreignUuid('binding_id')->constrained('treasury_bindings')->restrictOnDelete();
            $t->foreignUuid('statement_id')->constrained('treasury_statements')->restrictOnDelete();
            $t->date('from_date');
            $t->unsignedInteger('version');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 10)->default('pending');
            $t->unsignedTinyInteger('current_slot')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'treasury_account_id', 'version'], 'treasury_coverage_version');
            $t->unique(['book_id', 'treasury_account_id', 'current_slot'], 'treasury_coverage_current');
        });
        $invalidInsert =
            "NEW.status <> 'pending' OR NEW.current_slot IS NOT NULL OR NEW.reviewed_by IS NOT NULL OR NEW.reviewed_at IS NOT NULL OR NOT EXISTS (SELECT 1 FROM treasury_statements s WHERE s.id=NEW.statement_id AND s.book_id=NEW.book_id AND s.treasury_account_id=NEW.treasury_account_id AND s.binding_id=NEW.binding_id AND s.starts_on=NEW.from_date AND s.status <> 'void')";
        $invalidUpdate =
            "NOT COALESCE(((OLD.status='pending' AND NEW.status='approved' AND NEW.current_slot=1 AND OLD.reviewed_by IS NULL AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at IS NOT NULL) OR (OLD.status='approved' AND NEW.status='approved' AND OLD.current_slot=1 AND NEW.current_slot IS NULL AND NEW.reviewed_by IS NOT DISTINCT FROM OLD.reviewed_by AND NEW.reviewed_at IS NOT DISTINCT FROM OLD.reviewed_at) OR (OLD.status='pending' AND NEW.status='void' AND NEW.current_slot IS NULL AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL)), false)";
        $protectedPosting = "NEW.status='posted' AND EXISTS (
            SELECT 1 FROM treasury_coverage_policies p JOIN treasury_bindings b ON b.id=p.binding_id
            JOIN accounting_entry_lines l ON l.account_id=b.account_id
            WHERE p.book_id=NEW.book_id AND p.current_slot=1 AND l.entry_id=NEW.id AND NEW.entry_date < p.from_date
            AND EXISTS (SELECT 1 FROM treasury_statements s WHERE s.book_id=p.book_id AND s.treasury_account_id=p.treasury_account_id AND s.status='closed' AND s.ends_on>=p.from_date)
        )";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION treasury_coverage_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='INSERT' THEN IF {$invalidInsert} THEN RAISE EXCEPTION 'TREASURY_COVERAGE_STATE'; END IF; ELSE IF {$invalidUpdate} THEN RAISE EXCEPTION 'TREASURY_COVERAGE_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER treasury_coverage_state BEFORE INSERT OR UPDATE ON treasury_coverage_policies FOR EACH ROW EXECUTE FUNCTION treasury_coverage_state();
                CREATE TRIGGER treasury_coverage_history BEFORE UPDATE OR DELETE ON treasury_coverage_policies FOR EACH ROW EXECUTE FUNCTION treasury_history_guard('status','current_slot','reviewed_by','reviewed_at');
                CREATE OR REPLACE FUNCTION treasury_coverage_post() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$protectedPosting} THEN RAISE EXCEPTION 'TREASURY_COVERAGE_REOPEN'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER treasury_coverage_post BEFORE UPDATE ON accounting_entries FOR EACH ROW EXECUTE FUNCTION treasury_coverage_post();");
        } else {
            $invalidUpdate = str_replace('IS NOT DISTINCT FROM', 'IS', $invalidUpdate);
            $fields = implode(
                ',',
                array_diff(Schema::getColumnListing('treasury_coverage_policies'), [
                    'status',
                    'current_slot',
                    'reviewed_by',
                    'reviewed_at',
                ]),
            );
            DB::unprepared("CREATE TRIGGER treasury_coverage_insert BEFORE INSERT ON treasury_coverage_policies WHEN {$invalidInsert} BEGIN SELECT RAISE(ABORT,'TREASURY_COVERAGE_STATE'); END;
                CREATE TRIGGER treasury_coverage_state BEFORE UPDATE ON treasury_coverage_policies WHEN {$invalidUpdate} BEGIN SELECT RAISE(ABORT,'TREASURY_COVERAGE_STATE'); END;
                CREATE TRIGGER treasury_coverage_history_update BEFORE UPDATE OF {$fields} ON treasury_coverage_policies BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END;
                CREATE TRIGGER treasury_coverage_history_delete BEFORE DELETE ON treasury_coverage_policies BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END;
                CREATE TRIGGER treasury_coverage_post BEFORE UPDATE ON accounting_entries WHEN {$protectedPosting} BEGIN SELECT RAISE(ABORT,'TREASURY_COVERAGE_REOPEN'); END;");
        }
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS treasury_coverage_post'.
                (DB::getDriverName() === 'pgsql' ? ' ON accounting_entries' : ''),
        );
        Schema::dropIfExists('treasury_coverage_policies');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS treasury_coverage_state(); DROP FUNCTION IF EXISTS treasury_coverage_post();',
            );
        }
    }
};
