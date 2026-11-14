<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('kind', 24);
            $t->string('reference', 200);
            $t->string('counterparty', 200);
            $t->foreignUuid('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $t->unsignedBigInteger('principal');
            $t->date('effective_on');
            $t->date('due_on')->nullable();
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->string('document_sha256', 64);
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
            $t->foreignUuid('cancel_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->string('cancel_document_sha256', 64)->nullable();
            $t->unique(['book_id', 'reference']);
        });
        Schema::create('financing_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('financing_id')->constrained('financings')->restrictOnDelete();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->foreignUuid('binding_id')->constrained('treasury_bindings')->restrictOnDelete();
            $t->foreignUuid('entry_id')->unique()->constrained('accounting_entries')->restrictOnDelete();
            $t->foreignId('cash_line_id')->unique()->constrained('accounting_entry_lines')->restrictOnDelete();
            $t->string('direction', 12);
            $t->unsignedBigInteger('amount');
            $t->date('posted_on');
            $t->timestamp('occurred_at');
            $t->string('reference', 200);
            $t->unsignedInteger('revision');
            $t->unsignedTinyInteger('reference_slot')->nullable()->default(1);
            $t->uuid('replaces_id')->nullable();
            if (DB::getDriverName() !== 'pgsql') {
                $t->foreign('replaces_id')->references('id')->on('financing_movements')->restrictOnDelete();
            }
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->string('document_sha256', 64);
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
            $t->foreignUuid('reversal_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->string('reversal_document_sha256', 64)->nullable();
            $t->unique(
                ['book_id', 'treasury_account_id', 'reference', 'reference_slot'],
                'financing_movement_active_ref',
            );
            $t->unique(['book_id', 'treasury_account_id', 'reference', 'revision'], 'financing_movement_revision');
            $t->index(['financing_id', 'status', 'direction']);
        });
        if (DB::getDriverName() === 'pgsql') {
            Schema::table(
                'financing_movements',
                fn (Blueprint $t) => $t
                    ->foreign('replaces_id')
                    ->references('id')
                    ->on('financing_movements')
                    ->restrictOnDelete(),
            );
        }
        $sum = fn (
            $id,
            $direction,
        ) => "COALESCE((SELECT SUM(m.amount) FROM financing_movements m WHERE m.financing_id={$id} AND m.direction='{$direction}' AND m.status<>'reversed'),0)";
        $open = fn (
            $date,
        ) => "EXISTS (SELECT 1 FROM accounting_periods p JOIN accounting_years y ON y.id=p.year_id WHERE p.book_id=NEW.book_id AND p.status='open' AND y.status='open' AND p.starts_on<={$date} AND p.ends_on>={$date})";
        $chrono = fn (
            $id,
            $date,
        ) => "NOT EXISTS (SELECT 1 FROM financings f WHERE f.id={$id} AND (f.effective_on>{$date} OR f.approved_on>{$date} OR f.cancelled_on>{$date})) AND NOT EXISTS (SELECT 1 FROM financing_movements m WHERE m.financing_id={$id} AND (m.posted_on>{$date} OR m.reversed_on>{$date}))";
        $counter =
            "EXISTS (SELECT 1 FROM accounting_accounts a JOIN accounting_books b ON b.id=a.book_id WHERE a.id=NEW.account_id AND a.book_id=NEW.book_id AND a.active=true AND a.postable=true AND a.auxiliary_type='none' AND ((NEW.kind='capital_contribution' AND a.kind='equity') OR (NEW.kind IN ('founder_advance','loan') AND a.kind='liability')) AND (b.mode<>'live' OR b.reviewed_chart_version=b.chart_version) AND NOT EXISTS (SELECT 1 FROM treasury_bindings t WHERE t.book_id=b.id AND t.account_id=a.id))";
        $clean = fn (
            $prefix,
        ) => "EXISTS (SELECT 1 FROM private_documents d WHERE d.id=NEW.{$prefix}document_id AND d.sha256=NEW.{$prefix}document_sha256 AND d.scan_status='clean' AND d.purpose='accounting_evidence' AND d.supplier_id IS NULL)";
        $approvalEmpty =
            'NEW.approved_on IS NULL AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL AND NEW.approval_note IS NULL';
        $cancelEmpty =
            'NEW.cancelled_on IS NULL AND NEW.cancelled_by IS NULL AND NEW.cancelled_at IS NULL AND NEW.cancel_note IS NULL AND NEW.cancel_document_id IS NULL AND NEW.cancel_document_sha256 IS NULL';
        $create =
            "NEW.status='pending' AND NEW.version=1 AND NEW.principal BETWEEN 1 AND 1000000000000000000 AND LENGTH(TRIM(NEW.reference))>0 AND LENGTH(TRIM(NEW.counterparty))>0 AND (NEW.due_on IS NULL OR NEW.due_on>=NEW.effective_on) AND (NEW.kind<>'capital_contribution' OR NEW.due_on IS NULL) AND {$approvalEmpty} AND {$cancelEmpty} AND {$counter} AND ".
            $clean('');
        $separate = fn (
            $actor,
        ) => "NOT EXISTS (SELECT 1 FROM accounting_books b WHERE b.id=NEW.book_id AND b.mode='live' AND NEW.{$actor}=NEW.created_by)";
        $approve =
            "OLD.status='pending' AND NEW.status='approved' AND NEW.version=2 AND NEW.approved_on>=NEW.effective_on AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL AND LENGTH(TRIM(NEW.approval_note))>0 AND {$cancelEmpty} AND {$counter} AND ".
            $open('NEW.approved_on').
            ' AND '.
            $clean('').
            ' AND '.
            $separate('approved_by');
        $sameApproval =
            'NEW.approved_on IS NOT DISTINCT FROM OLD.approved_on AND NEW.approved_by IS NOT DISTINCT FROM OLD.approved_by AND NEW.approved_at IS NOT DISTINCT FROM OLD.approved_at AND NEW.approval_note IS NOT DISTINCT FROM OLD.approval_note';
        $cancel =
            "OLD.status IN ('pending','approved') AND NEW.status='cancelled' AND NEW.version=OLD.version+1 AND {$sameApproval} AND NEW.cancelled_on IS NOT NULL AND NEW.cancelled_by IS NOT NULL AND NEW.cancelled_at IS NOT NULL AND LENGTH(TRIM(NEW.cancel_note))>0 AND ".
            $sum('NEW.id', 'funding').
            '<NEW.principal AND '.
            $open('NEW.cancelled_on').
            ' AND '.
            $chrono('NEW.id', 'NEW.cancelled_on').
            ' AND '.
            $clean('cancel_').
            ' AND '.
            $separate('cancelled_by');
        $reverseEmpty =
            'NEW.reversal_entry_id IS NULL AND NEW.reversed_on IS NULL AND NEW.reversed_by IS NULL AND NEW.reversed_at IS NULL AND NEW.reversal_note IS NULL AND NEW.reversal_document_id IS NULL AND NEW.reversal_document_sha256 IS NULL';
        $revision =
            "((NEW.revision=1 AND NEW.replaces_id IS NULL AND NOT EXISTS (SELECT 1 FROM financing_movements h WHERE h.book_id=NEW.book_id AND h.treasury_account_id=NEW.treasury_account_id AND h.reference=NEW.reference)) OR EXISTS (SELECT 1 FROM financing_movements h WHERE h.id=NEW.replaces_id AND h.book_id=NEW.book_id AND h.treasury_account_id=NEW.treasury_account_id AND h.reference=NEW.reference AND h.direction=NEW.direction AND h.status='reversed' AND h.reference_slot IS NULL AND NEW.revision=h.revision+1 AND NEW.posted_on>=h.reversed_on AND NOT EXISTS (SELECT 1 FROM financing_movements z WHERE z.book_id=h.book_id AND z.treasury_account_id=h.treasury_account_id AND z.reference=h.reference AND z.revision>h.revision)))";
        $movement =
            "NEW.status='recorded' AND NEW.version=1 AND NEW.reference_slot=1 AND NEW.amount BETWEEN 1 AND 1000000000000000000 AND LENGTH(TRIM(NEW.reference))>0 AND {$reverseEmpty} AND {$revision} AND ".
            $open('NEW.posted_on').
            ' AND '.
            $chrono('NEW.financing_id', 'NEW.posted_on').
            ' AND '.
            $clean('').
            " AND EXISTS (
            SELECT 1 FROM financings f JOIN accounting_books bk ON bk.id=f.book_id JOIN accounting_accounts ca ON ca.id=f.account_id
            JOIN treasury_bindings b ON b.id=NEW.binding_id JOIN treasury_accounts ta ON ta.id=b.treasury_account_id JOIN accounting_accounts cash ON cash.id=b.account_id
            JOIN accounting_entries e ON e.id=NEW.entry_id JOIN accounting_entry_lines l ON l.id=NEW.cash_line_id
            WHERE f.id=NEW.financing_id AND f.book_id=NEW.book_id AND b.book_id=f.book_id AND b.treasury_account_id=NEW.treasury_account_id AND b.inflow_side='debit' AND b.account_id<>f.account_id
            AND ta.active=true AND cash.book_id=f.book_id AND ca.book_id=f.book_id AND cash.active=true AND cash.postable=true AND cash.kind='asset' AND ca.active=true AND ca.postable=true AND ca.auxiliary_type='none' AND ((f.kind='capital_contribution' AND ca.kind='equity') OR (f.kind IN ('founder_advance','loan') AND ca.kind='liability'))
            AND b.chart_version=bk.chart_version AND (bk.mode<>'live' OR (b.reviewed_by IS NOT NULL AND bk.reviewed_chart_version=bk.chart_version AND NEW.created_by<>f.created_by AND NEW.created_by<>e.posted_by))
            AND NOT EXISTS (SELECT 1 FROM treasury_bindings z WHERE z.book_id=b.book_id AND z.treasury_account_id=b.treasury_account_id AND z.version>b.version)
            AND NOT EXISTS (SELECT 1 FROM treasury_bindings z WHERE z.book_id=f.book_id AND z.account_id=f.account_id)
            AND e.book_id=f.book_id AND e.status='posted' AND e.source_id IS NULL AND e.reversal_of IS NULL AND e.entry_date=NEW.posted_on AND l.entry_id=e.id AND l.account_id=b.account_id
            AND (SELECT COUNT(*) FROM accounting_entry_lines x WHERE x.entry_id=e.id)=2
            AND EXISTS (SELECT 1 FROM accounting_entry_lines x WHERE x.entry_id=e.id AND x.account_id=f.account_id AND ((NEW.direction='funding' AND l.debit=NEW.amount AND l.credit=0 AND x.credit=NEW.amount AND x.debit=0) OR (NEW.direction='repayment' AND l.credit=NEW.amount AND l.debit=0 AND x.debit=NEW.amount AND x.credit=0)))
            AND NOT EXISTS (SELECT 1 FROM accounting_entries z WHERE z.reversal_of=e.id) AND NOT EXISTS (SELECT 1 FROM expense_payments z WHERE z.entry_id=e.id) AND NOT EXISTS (SELECT 1 FROM withdrawal_receipts z WHERE z.entry_id=e.id)
            AND ((NEW.direction='funding' AND (f.status='approved' OR (f.status='cancelled' AND EXISTS (SELECT 1 FROM financing_movements h WHERE h.id=NEW.replaces_id AND h.financing_id=f.id AND NEW.amount<=h.amount))) AND NEW.amount+".
            $sum('f.id', 'funding').
            "<=f.principal) OR (NEW.direction='repayment' AND f.status IN ('approved','cancelled') AND f.kind<>'capital_contribution' AND NEW.amount+".
            $sum('f.id', 'repayment').
            '<='.
            $sum('f.id', 'funding').
            ')) )';
        $unmatched =
            "NOT EXISTS (SELECT 1 FROM treasury_match_ledger l JOIN treasury_matches m ON m.id=l.match_id WHERE l.ledger_line_id=NEW.cash_line_id AND l.active_slot=1 AND m.status='active')";
        $capacity =
            "(NEW.direction='repayment' OR ".
            $sum('NEW.financing_id', 'funding').
            '-NEW.amount>='.
            $sum('NEW.financing_id', 'repayment').
            ')';
        $reverse =
            "({$capacity}) AND {$unmatched} AND ((OLD.status='recorded' AND NEW.status='reversing' AND NEW.version=1 AND NEW.reference_slot=1 AND {$reverseEmpty}) OR (OLD.status='reversing' AND NEW.status='reversed' AND NEW.version=2 AND NEW.reference_slot IS NULL AND NEW.reversed_on IS NOT NULL AND NEW.reversed_by IS NOT NULL AND NEW.reversed_at IS NOT NULL AND LENGTH(TRIM(NEW.reversal_note))>0 AND ".
            $open('NEW.reversed_on').
            ' AND '.
            $chrono('NEW.financing_id', 'NEW.reversed_on').
            ' AND '.
            $clean('reversal_').
            " AND EXISTS (SELECT 1 FROM accounting_entries e WHERE e.id=NEW.reversal_entry_id AND e.reversal_of=NEW.entry_id AND e.book_id=NEW.book_id AND e.status='posted' AND e.entry_date=NEW.reversed_on)))";
        $ordinary =
            "NEW.status='posted' AND NEW.reversal_of IS NOT NULL AND EXISTS (SELECT 1 FROM financing_movements m WHERE (m.entry_id=NEW.reversal_of AND m.status='recorded') OR m.reversal_entry_id=NEW.reversal_of)";
        $expense = 'EXISTS (SELECT 1 FROM financing_movements m WHERE m.entry_id=NEW.entry_id)';
        $mutable = [
            'financings' => [
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
                'cancel_document_id',
                'cancel_document_sha256',
            ],
            'financing_movements' => [
                'status',
                'version',
                'reference_slot',
                'reversal_entry_id',
                'reversed_on',
                'reversed_by',
                'reversed_at',
                'reversal_note',
                'reversal_document_id',
                'reversal_document_sha256',
            ],
        ];
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION financing_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE; IF TG_OP='INSERT' THEN IF NOT COALESCE(({$create}),false) THEN RAISE EXCEPTION 'FINANCING_STATE'; END IF; ELSE IF NOT COALESCE((({$approve}) OR ({$cancel})),false) THEN RAISE EXCEPTION 'FINANCING_STATE'; END IF; END IF; RETURN NEW; END \$\$;
            CREATE OR REPLACE FUNCTION financing_movement_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE; IF TG_OP='INSERT' THEN IF NOT COALESCE(({$movement}),false) THEN RAISE EXCEPTION 'FINANCING_MOVEMENT'; END IF; ELSE IF NOT COALESCE(({$reverse}),false) THEN RAISE EXCEPTION 'FINANCING_STATE'; END IF; END IF; RETURN NEW; END \$\$;
            CREATE OR REPLACE FUNCTION financing_reversal_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$ordinary} THEN RAISE EXCEPTION 'FINANCING_LINKED_REVERSAL'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER financing_reversal_guard BEFORE UPDATE ON accounting_entries FOR EACH ROW EXECUTE FUNCTION financing_reversal_guard();
            CREATE OR REPLACE FUNCTION financing_expense_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$expense} THEN RAISE EXCEPTION 'FINANCING_USED'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER financing_expense_guard BEFORE INSERT ON expense_payments FOR EACH ROW EXECUTE FUNCTION financing_expense_guard();");
            foreach ($mutable as $table => $fields) {
                $function = $table === 'financings' ? 'financing_state' : 'financing_movement_state';
                $args = implode(',', array_map(fn ($v) => "'{$v}'", $fields));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_state BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}(); CREATE TRIGGER {$table}_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION treasury_history_guard({$args});",
                );
            }
        } else {
            $cancel = str_replace('IS NOT DISTINCT FROM', 'IS', $cancel);
            DB::unprepared("CREATE TRIGGER financing_insert BEFORE INSERT ON financings WHEN NOT COALESCE(({$create} AND typeof(NEW.principal)='integer'),false) BEGIN SELECT RAISE(ABORT,'FINANCING_STATE'); END;
            CREATE TRIGGER financing_update BEFORE UPDATE ON financings WHEN NOT COALESCE((({$approve}) OR ({$cancel})),false) BEGIN SELECT RAISE(ABORT,'FINANCING_STATE'); END;
            CREATE TRIGGER financing_movement_insert BEFORE INSERT ON financing_movements WHEN NOT COALESCE(({$movement} AND typeof(NEW.amount)='integer'),false) BEGIN SELECT RAISE(ABORT,'FINANCING_MOVEMENT'); END;
            CREATE TRIGGER financing_movement_update BEFORE UPDATE ON financing_movements WHEN NOT COALESCE(({$reverse}),false) BEGIN SELECT RAISE(ABORT,'FINANCING_STATE'); END;
            CREATE TRIGGER financing_reversal_guard BEFORE UPDATE ON accounting_entries WHEN {$ordinary} BEGIN SELECT RAISE(ABORT,'FINANCING_LINKED_REVERSAL'); END;
            CREATE TRIGGER financing_expense_guard BEFORE INSERT ON expense_payments WHEN {$expense} BEGIN SELECT RAISE(ABORT,'FINANCING_USED'); END;");
            foreach ($mutable as $table => $fields) {
                $immutable = implode(',', array_diff(Schema::getColumnListing($table), $fields));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_history BEFORE UPDATE OF {$immutable} ON {$table} BEGIN SELECT RAISE(ABORT,'FINANCING_IMMUTABLE'); END; CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'FINANCING_IMMUTABLE'); END;",
                );
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS financing_expense_guard'.
                (DB::getDriverName() === 'pgsql' ? ' ON expense_payments' : ''),
        );
        DB::unprepared(
            'DROP TRIGGER IF EXISTS financing_reversal_guard'.
                (DB::getDriverName() === 'pgsql' ? ' ON accounting_entries' : ''),
        );
        Schema::dropIfExists('financing_movements');
        Schema::dropIfExists('financings');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS financing_state(); DROP FUNCTION IF EXISTS financing_movement_state(); DROP FUNCTION IF EXISTS financing_reversal_guard(); DROP FUNCTION IF EXISTS financing_expense_guard();',
            );
        }
    }
};
