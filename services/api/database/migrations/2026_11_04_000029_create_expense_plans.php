<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->foreignUuid('binding_id')->constrained('treasury_bindings')->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('category', 30);
            $t->string('reference_key', 200);
            $t->date('planned_on');
            $t->date('due_on');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 12)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->date('approved_on')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('approval_note')->nullable();
            $t->date('cancelled_on')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancel_note')->nullable();
            $t->unique(['book_id', 'reference_key']);
            $t->index(['book_id', 'treasury_account_id', 'due_on']);
        });
        Schema::create('expense_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('plan_id')->constrained('expense_plans')->restrictOnDelete();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('binding_id')->constrained('treasury_bindings')->restrictOnDelete();
            $t->foreignUuid('entry_id')->unique()->constrained('accounting_entries')->restrictOnDelete();
            $t->foreignId('ledger_line_id')->unique()->constrained('accounting_entry_lines')->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->date('posted_on');
            $t->timestamp('paid_at');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 12)->default('recorded');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->foreignUuid('reversal_entry_id')
                ->nullable()
                ->unique()
                ->constrained('accounting_entries')
                ->restrictOnDelete();
            $t->date('reversed_on')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->text('reversal_note')->nullable();
        });
        $open = fn (
            $date,
        ) => "EXISTS (SELECT 1 FROM accounting_periods p JOIN accounting_years y ON y.id=p.year_id WHERE p.book_id=NEW.book_id AND p.status='open' AND y.status='open' AND p.starts_on<={$date} AND p.ends_on>={$date})";
        $chrono = fn (
            $plan,
            $date,
        ) => "NOT EXISTS (SELECT 1 FROM expense_plans g WHERE g.id={$plan} AND (g.planned_on>{$date} OR g.approved_on>{$date} OR g.cancelled_on>{$date})) AND NOT EXISTS (SELECT 1 FROM expense_payments p WHERE p.plan_id={$plan} AND (p.posted_on>{$date} OR p.reversed_on>{$date}))";
        $approvalEmpty =
            'NEW.approved_on IS NULL AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL AND NEW.approval_note IS NULL';
        $cancelEmpty =
            'NEW.cancelled_on IS NULL AND NEW.cancelled_by IS NULL AND NEW.cancelled_at IS NULL AND NEW.cancel_note IS NULL';
        $reversalEmpty =
            'NEW.reversal_entry_id IS NULL AND NEW.reversed_on IS NULL AND NEW.reversed_by IS NULL AND NEW.reversed_at IS NULL AND NEW.reversal_note IS NULL';
        $pInsert =
            "NEW.status='pending' AND NEW.version=1 AND NEW.amount BETWEEN 1 AND 1000000000000000000 AND NEW.category IN ('provider_fee','delivery_cost','operating_expense') AND NEW.due_on>=NEW.planned_on AND {$approvalEmpty} AND {$cancelEmpty} AND EXISTS (SELECT 1 FROM treasury_bindings b WHERE b.id=NEW.binding_id AND b.book_id=NEW.book_id AND b.treasury_account_id=NEW.treasury_account_id) AND ".
            $open('NEW.planned_on');
        $approve =
            "OLD.status='pending' AND NEW.status='approved' AND NEW.version=2 AND NEW.approved_on IS NOT NULL AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL AND LENGTH(TRIM(NEW.approval_note))>0 AND NEW.approved_on>=NEW.planned_on AND {$cancelEmpty} AND NOT EXISTS (SELECT 1 FROM accounting_books b WHERE b.id=NEW.book_id AND b.mode='live' AND NEW.approved_by=OLD.created_by) AND ".
            $open('NEW.approved_on');
        $sameApproval =
            'NEW.approved_on IS NOT DISTINCT FROM OLD.approved_on AND NEW.approved_by IS NOT DISTINCT FROM OLD.approved_by AND NEW.approved_at IS NOT DISTINCT FROM OLD.approved_at AND NEW.approval_note IS NOT DISTINCT FROM OLD.approval_note';
        $cancel =
            "OLD.status IN ('pending','approved') AND NEW.status='cancelled' AND NEW.version=OLD.version+1 AND {$sameApproval} AND NEW.cancelled_on IS NOT NULL AND NEW.cancelled_by IS NOT NULL AND NEW.cancelled_at IS NOT NULL AND LENGTH(TRIM(NEW.cancel_note))>0 AND ".
            $open('NEW.cancelled_on').
            ' AND '.
            $chrono('NEW.id', 'NEW.cancelled_on');
        $payInsert =
            "NEW.status='recorded' AND NEW.version=1 AND {$reversalEmpty} AND NEW.amount BETWEEN 1 AND 1000000000000000000 AND ".
            $open('NEW.posted_on').
            ' AND '.
            $chrono('NEW.plan_id', 'NEW.posted_on').
            " AND EXISTS (
            SELECT 1 FROM expense_plans g JOIN accounting_entries e ON e.id=NEW.entry_id JOIN accounting_entry_lines l ON l.id=NEW.ledger_line_id JOIN treasury_bindings b ON b.id=NEW.binding_id JOIN accounting_books bk ON bk.id=g.book_id
            WHERE g.id=NEW.plan_id AND g.book_id=NEW.book_id AND g.status='approved' AND e.book_id=g.book_id AND e.status='posted' AND e.source_id IS NULL AND e.reversal_of IS NULL AND e.entry_date=NEW.posted_on
            AND l.entry_id=e.id AND b.book_id=g.book_id AND b.treasury_account_id=g.treasury_account_id AND l.account_id=b.account_id
            AND ((b.inflow_side='debit' AND l.credit=NEW.amount AND l.debit=0) OR (b.inflow_side='credit' AND l.debit=NEW.amount AND l.credit=0))
            AND (bk.mode<>'live' OR (NEW.created_by<>g.created_by AND NEW.created_by<>e.posted_by))
            AND NOT EXISTS (SELECT 1 FROM accounting_entries r WHERE r.reversal_of=e.id)
            AND NOT EXISTS (SELECT 1 FROM accounting_entry_lines x JOIN treasury_bindings z ON z.account_id=x.account_id AND z.book_id=g.book_id WHERE x.entry_id=e.id AND x.id<>l.id)
            AND NEW.amount + COALESCE((SELECT SUM(p.amount) FROM expense_payments p WHERE p.plan_id=g.id AND p.status<>'reversed'),0)<=g.amount)";
        $payUpdate =
            "(OLD.status='recorded' AND NEW.status='reversing' AND NEW.version=1 AND {$reversalEmpty}) OR (OLD.status='reversing' AND NEW.status='reversed' AND NEW.version=2 AND NEW.reversed_on IS NOT NULL AND NEW.reversed_by IS NOT NULL AND NEW.reversed_at IS NOT NULL AND LENGTH(TRIM(NEW.reversal_note))>0 AND ".
            $open('NEW.reversed_on').
            ' AND '.
            $chrono('NEW.plan_id', 'NEW.reversed_on').
            " AND EXISTS (SELECT 1 FROM accounting_entries e WHERE e.id=NEW.reversal_entry_id AND e.reversal_of=NEW.entry_id AND e.book_id=NEW.book_id AND e.status='posted' AND e.entry_date=NEW.reversed_on))";
        $reverseGuard =
            "NEW.status='posted' AND NEW.reversal_of IS NOT NULL AND EXISTS (SELECT 1 FROM expense_payments p WHERE (p.entry_id=NEW.reversal_of AND p.status='recorded') OR p.reversal_entry_id=NEW.reversal_of)";
        $mutable = [
            'expense_plans' => [
                'status',
                'version',
                'approved_on',
                'approved_by',
                'approved_at',
                'approval_note',
                'cancelled_on',
                'cancelled_by',
                'cancelled_at',
                'cancel_note',
            ],
            'expense_payments' => [
                'status',
                'version',
                'reversal_entry_id',
                'reversed_on',
                'reversed_by',
                'reversed_at',
                'reversal_note',
            ],
        ];
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION expense_history_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'EXPENSE_IMMUTABLE'; END IF;
                IF (to_jsonb(NEW)-COALESCE(TG_ARGV,ARRAY[]::text[])) IS DISTINCT FROM (to_jsonb(OLD)-COALESCE(TG_ARGV,ARRAY[]::text[])) THEN RAISE EXCEPTION 'EXPENSE_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE OR REPLACE FUNCTION expense_plan_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE;
                IF TG_OP='INSERT' THEN IF NOT COALESCE(({$pInsert}),false) THEN RAISE EXCEPTION 'EXPENSE_STATE'; END IF;
                ELSE IF NOT COALESCE((({$approve}) OR ({$cancel})),false) THEN RAISE EXCEPTION 'EXPENSE_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE OR REPLACE FUNCTION expense_payment_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE;
                IF TG_OP='INSERT' THEN IF NOT COALESCE(({$payInsert}),false) THEN RAISE EXCEPTION 'EXPENSE_POSTING'; END IF;
                ELSE IF NOT COALESCE(({$payUpdate}),false) THEN RAISE EXCEPTION 'EXPENSE_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE OR REPLACE FUNCTION expense_reversal_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$reverseGuard} THEN RAISE EXCEPTION 'EXPENSE_REVERSAL_ROUTE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER expense_reversal_guard BEFORE UPDATE ON accounting_entries FOR EACH ROW EXECUTE FUNCTION expense_reversal_guard();");
            foreach ($mutable as $table => $fields) {
                $function = $table === 'expense_plans' ? 'expense_plan_state' : 'expense_payment_state';
                $args = implode(',', array_map(fn ($f) => "'{$f}'", $fields));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_state BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}(); CREATE TRIGGER {$table}_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION expense_history_guard({$args});",
                );
            }
        } else {
            $cancel = str_replace('IS NOT DISTINCT FROM', 'IS', $cancel);
            DB::unprepared("CREATE TRIGGER expense_plan_insert BEFORE INSERT ON expense_plans WHEN NOT COALESCE(({$pInsert} AND typeof(NEW.amount)='integer'),false) BEGIN SELECT RAISE(ABORT,'EXPENSE_STATE'); END;
                CREATE TRIGGER expense_plan_state BEFORE UPDATE ON expense_plans WHEN NOT COALESCE((({$approve}) OR ({$cancel})),false) BEGIN SELECT RAISE(ABORT,'EXPENSE_STATE'); END;
                CREATE TRIGGER expense_payment_insert BEFORE INSERT ON expense_payments WHEN NOT COALESCE(({$payInsert} AND typeof(NEW.amount)='integer'),false) BEGIN SELECT RAISE(ABORT,'EXPENSE_POSTING'); END;
                CREATE TRIGGER expense_payment_state BEFORE UPDATE ON expense_payments WHEN NOT COALESCE(({$payUpdate}),false) BEGIN SELECT RAISE(ABORT,'EXPENSE_STATE'); END;
                CREATE TRIGGER expense_reversal_guard BEFORE UPDATE ON accounting_entries WHEN {$reverseGuard} BEGIN SELECT RAISE(ABORT,'EXPENSE_REVERSAL_ROUTE'); END;");
            foreach ($mutable as $table => $fields) {
                $immutable = implode(',', array_diff(Schema::getColumnListing($table), $fields));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_history BEFORE UPDATE OF {$immutable} ON {$table} BEGIN SELECT RAISE(ABORT,'EXPENSE_IMMUTABLE'); END; CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'EXPENSE_IMMUTABLE'); END;",
                );
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS expense_reversal_guard'.
                (DB::getDriverName() === 'pgsql' ? ' ON accounting_entries' : ''),
        );
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expense_plans');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS expense_history_guard(); DROP FUNCTION IF EXISTS expense_plan_state(); DROP FUNCTION IF EXISTS expense_payment_state(); DROP FUNCTION IF EXISTS expense_reversal_guard();',
            );
        }
    }
};
