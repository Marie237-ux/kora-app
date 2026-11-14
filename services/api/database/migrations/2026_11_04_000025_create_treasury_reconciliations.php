<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_bindings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('chart_version');
            $t->string('inflow_side', 6);
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'treasury_account_id', 'version'], 'treasury_binding_version');
        });
        Schema::create('treasury_statements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('binding_id')->constrained('treasury_bindings')->restrictOnDelete();
            $t->foreignUuid('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->string('reference', 120);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('opening_balance', 40);
            $t->string('closing_balance', 40);
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 10)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'treasury_account_id', 'reference', 'active_slot'], 'treasury_statement_reference');
            $t->index(['book_id', 'treasury_account_id', 'starts_on'], 'treasury_statement_dates');
        });
        Schema::create('treasury_statement_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('statement_id')->constrained('treasury_statements')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->string('external_id', 120);
            $t->date('booked_on');
            $t->string('reference', 200);
            $t->string('direction', 3);
            $t->unsignedBigInteger('amount');
            $t->unique(['statement_id', 'external_id']);
            $t->unique(['statement_id', 'position']);
        });
        Schema::create('treasury_matches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('statement_id')->constrained('treasury_statements')->restrictOnDelete();
            $t->string('net_amount', 40);
            $t->text('note');
            $t->string('status', 10)->default('active');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('treasury_match_rows', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('match_id')->constrained('treasury_matches')->restrictOnDelete();
            $t->foreignUuid('statement_line_id')->constrained('treasury_statement_lines')->restrictOnDelete();
            $t->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $t->unique(['statement_line_id', 'active_slot']);
        });
        Schema::create('treasury_match_ledger', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('match_id')->constrained('treasury_matches')->restrictOnDelete();
            $t->foreignId('ledger_line_id')->constrained('accounting_entry_lines')->restrictOnDelete();
            $t->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $t->unique(['ledger_line_id', 'active_slot']);
        });
        $this->guards();
    }

    private function guards(): void
    {
        $mutable = [
            'treasury_bindings' => ['reviewed_by', 'reviewed_at'],
            'treasury_statements' => ['status', 'version', 'active_slot'],
            'treasury_statement_lines' => [],
            'treasury_matches' => ['status'],
            'treasury_match_rows' => ['active_slot'],
            'treasury_match_ledger' => ['active_slot'],
        ];
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION treasury_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'TREASURY_IMMUTABLE'; END IF;
                  IF (to_jsonb(OLD) - TG_ARGV) <> (to_jsonb(NEW) - TG_ARGV) THEN RAISE EXCEPTION 'TREASURY_IMMUTABLE'; END IF;
                  RETURN NEW;
                END $$;
                CREATE OR REPLACE FUNCTION treasury_post_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                  IF NEW.status = 'posted' AND EXISTS (
                    SELECT 1 FROM treasury_statements s JOIN treasury_bindings b ON b.id=s.binding_id
                    JOIN accounting_entry_lines l ON l.account_id=b.account_id
                    WHERE s.book_id=NEW.book_id AND s.status='closed' AND l.entry_id=NEW.id
                    AND NEW.entry_date BETWEEN s.starts_on AND s.ends_on
                  ) THEN RAISE EXCEPTION 'TREASURY_REOPEN_REQUIRED'; END IF;
                  RETURN NEW;
                END $$;
                CREATE TRIGGER treasury_post_guard_trigger BEFORE UPDATE ON accounting_entries FOR EACH ROW EXECUTE FUNCTION treasury_post_guard();
                SQL
                ,
            );
        } else {
            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER treasury_post_guard_trigger BEFORE UPDATE ON accounting_entries WHEN NEW.status='posted' BEGIN
                  SELECT CASE WHEN EXISTS (
                    SELECT 1 FROM treasury_statements s JOIN treasury_bindings b ON b.id=s.binding_id
                    JOIN accounting_entry_lines l ON l.account_id=b.account_id
                    WHERE s.book_id=NEW.book_id AND s.status='closed' AND l.entry_id=NEW.id
                    AND NEW.entry_date BETWEEN s.starts_on AND s.ends_on
                  ) THEN RAISE(ABORT,'TREASURY_REOPEN_REQUIRED') END;
                END;
                SQL
                ,
            );
        }
        foreach ($mutable as $table => $allowed) {
            if (DB::getDriverName() === 'pgsql') {
                $args = implode(',', array_map(fn ($s) => "'{$s}'", $allowed));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION treasury_history_guard({$args})",
                );
            } else {
                $columns = array_diff(Schema::getColumnListing($table), $allowed);
                $fields = implode(',', $columns);
                DB::unprepared(
                    "CREATE TRIGGER {$table}_history_update BEFORE UPDATE OF {$fields} ON {$table} BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END",
                );
                DB::unprepared(
                    "CREATE TRIGGER {$table}_history_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END",
                );
            }
        }
        $insertChecks = [
            'treasury_statement_lines' => "(SELECT status FROM treasury_statements WHERE id=NEW.statement_id) <> 'importing'",
            'treasury_match_rows' => "(SELECT status FROM treasury_matches WHERE id=NEW.match_id) <> 'assembling'",
            'treasury_match_ledger' => "(SELECT status FROM treasury_matches WHERE id=NEW.match_id) <> 'assembling'",
        ];
        foreach ($insertChecks as $table => $condition) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(
                    "CREATE OR REPLACE FUNCTION {$table}_insert_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$condition} THEN RAISE EXCEPTION 'TREASURY_IMMUTABLE'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_insert_guard();",
                );
            } else {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'TREASURY_IMMUTABLE'); END",
                );
            }
        }
        $transitions = [
            'treasury_statements' => "NOT ((OLD.status='importing' AND NEW.status='open') OR (OLD.status='open' AND NEW.status IN ('open','closed','void')) OR (OLD.status='closed' AND NEW.status='open'))",
            'treasury_matches' => "NOT ((OLD.status='assembling' AND NEW.status='active') OR (OLD.status='active' AND NEW.status='void'))",
            'treasury_match_rows' => 'NOT (OLD.active_slot=1 AND NEW.active_slot IS NULL)',
            'treasury_match_ledger' => 'NOT (OLD.active_slot=1 AND NEW.active_slot IS NULL)',
            'treasury_bindings' => 'OLD.reviewed_by IS NOT NULL OR NEW.reviewed_by IS NULL OR NEW.reviewed_at IS NULL',
        ];
        foreach ($transitions as $table => $condition) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(
                    "CREATE OR REPLACE FUNCTION {$table}_state_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$condition} THEN RAISE EXCEPTION 'TREASURY_STATE'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER {$table}_state_guard BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_state_guard();",
                );
            } else {
                DB::unprepared(
                    "CREATE TRIGGER {$table}_state_guard BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'TREASURY_STATE'); END",
                );
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS treasury_post_guard_trigger'.
                (DB::getDriverName() === 'pgsql' ? ' ON accounting_entries' : ''),
        );
        foreach (
            [
                'treasury_match_ledger',
                'treasury_match_rows',
                'treasury_matches',
                'treasury_statement_lines',
                'treasury_statements',
                'treasury_bindings',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS treasury_history_guard(); DROP FUNCTION IF EXISTS treasury_post_guard();',
            );
            foreach (['treasury_statement_lines', 'treasury_match_rows', 'treasury_match_ledger'] as $table) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$table}_insert_guard();");
            }
            foreach (
                [
                    'treasury_statements',
                    'treasury_matches',
                    'treasury_match_rows',
                    'treasury_match_ledger',
                    'treasury_bindings',
                ] as $table
            ) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$table}_state_guard();");
            }
        }
    }
};
