<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            foreach (['source', 'destination'] as $side) {
                $t->foreignUuid($side.'_account_id')
                    ->constrained('treasury_accounts')
                    ->restrictOnDelete();
                $t->foreignUuid($side.'_binding_id')
                    ->constrained('treasury_bindings')
                    ->restrictOnDelete();
            }
            $t->string('reference', 200);
            $t->unsignedInteger('revision');
            $t->unsignedTinyInteger('reference_slot')->nullable()->default(1);
            $t->uuid('replaces_id')->nullable();
            if (DB::getDriverName() !== 'pgsql') {
                $t->foreign('replaces_id')->references('id')->on('withdrawals')->restrictOnDelete();
            }
            $t->unsignedBigInteger('amount');
            $t->date('requested_on');
            $t->timestamp('requested_at');
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
            $t->date('closed_on')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->text('close_note')->nullable();
            $t->foreignUuid('close_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->string('close_document_sha256', 64)->nullable();
            $t->unique(['book_id', 'source_account_id', 'reference', 'reference_slot'], 'withdrawal_reference_active');
            $t->unique(['book_id', 'source_account_id', 'reference', 'revision'], 'withdrawal_reference_revision');
            $t->index(['book_id', 'status', 'requested_on']);
        });
        Schema::create('withdrawal_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('withdrawal_id')->constrained('withdrawals')->restrictOnDelete();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('source_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            foreach (['source', 'destination'] as $side) {
                $t->foreignUuid($side.'_binding_id')
                    ->constrained('treasury_bindings')
                    ->restrictOnDelete();
            }
            $t->foreignUuid('entry_id')->unique()->constrained('accounting_entries')->restrictOnDelete();
            foreach (['source', 'destination'] as $side) {
                $t->foreignId($side.'_line_id')
                    ->unique()
                    ->constrained('accounting_entry_lines')
                    ->restrictOnDelete();
            }
            foreach (['gross', 'net', 'fee'] as $field) {
                $t->unsignedBigInteger($field);
            }
            $t->date('posted_on');
            $t->timestamp('received_at');
            $t->string('reference', 200);
            $t->unsignedInteger('revision');
            $t->unsignedTinyInteger('reference_slot')->nullable()->default(1);
            $t->uuid('replaces_id')->nullable();
            if (DB::getDriverName() !== 'pgsql') {
                $t->foreign('replaces_id')->references('id')->on('withdrawal_receipts')->restrictOnDelete();
            }
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
            $t->foreignUuid('reversal_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->string('reversal_document_sha256', 64)->nullable();
            $t->unique(
                ['book_id', 'source_account_id', 'reference', 'reference_slot'],
                'withdrawal_receipt_reference_active',
            );
            $t->unique(
                ['book_id', 'source_account_id', 'reference', 'revision'],
                'withdrawal_receipt_reference_revision',
            );
        });
        $open = fn (
            $date,
        ) => "EXISTS (SELECT 1 FROM accounting_periods p JOIN accounting_years y ON y.id=p.year_id WHERE p.book_id=NEW.book_id AND p.status='open' AND y.status='open' AND p.starts_on<={$date} AND p.ends_on>={$date})";
        $chrono = fn (
            $id,
            $date,
        ) => "NOT EXISTS (SELECT 1 FROM withdrawals w WHERE w.id={$id} AND (w.requested_on>{$date} OR w.approved_on>{$date} OR w.closed_on>{$date})) AND NOT EXISTS (SELECT 1 FROM withdrawal_receipts r WHERE r.withdrawal_id={$id} AND (r.posted_on>{$date} OR r.reversed_on>{$date}))";
        $spent = fn (
            $id,
        ) => "COALESCE((SELECT SUM(r.gross) FROM withdrawal_receipts r WHERE r.withdrawal_id={$id} AND r.status<>'reversed'),0)";
        $revision = fn (
            $table,
            $oldStatus,
            $date,
            $oldDate,
        ) => "((NEW.revision=1 AND NEW.replaces_id IS NULL AND NOT EXISTS (SELECT 1 FROM {$table} h WHERE h.book_id=NEW.book_id AND h.source_account_id=NEW.source_account_id AND h.reference=NEW.reference)) OR EXISTS (SELECT 1 FROM {$table} h WHERE h.id=NEW.replaces_id AND h.book_id=NEW.book_id AND h.source_account_id=NEW.source_account_id AND h.reference=NEW.reference AND h.status='{$oldStatus}' AND h.reference_slot IS NULL AND NEW.revision=h.revision+1 AND {$date}>=h.{$oldDate} AND NOT EXISTS (SELECT 1 FROM {$table} z WHERE z.book_id=h.book_id AND z.source_account_id=h.source_account_id AND z.reference=h.reference AND z.revision>h.revision)))";
        if (DB::getDriverName() === 'pgsql') {
            foreach (['withdrawals', 'withdrawal_receipts'] as $table) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->foreign('replaces_id')->references('id')->on($table)->restrictOnDelete();
                });
            }
        }
        $approvalEmpty =
            'NEW.approved_on IS NULL AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL AND NEW.approval_note IS NULL';
        $closeEmpty =
            'NEW.closed_on IS NULL AND NEW.closed_by IS NULL AND NEW.closed_at IS NULL AND NEW.close_note IS NULL AND NEW.close_document_id IS NULL AND NEW.close_document_sha256 IS NULL';
        $reversalEmpty =
            'NEW.reversal_entry_id IS NULL AND NEW.reversed_on IS NULL AND NEW.reversed_by IS NULL AND NEW.reversed_at IS NULL AND NEW.reversal_note IS NULL AND NEW.reversal_document_id IS NULL AND NEW.reversal_document_sha256 IS NULL';
        $requestInsert =
            "NEW.status='pending' AND NEW.version=1 AND NEW.reference_slot=1 AND NEW.amount BETWEEN 1 AND 1000000000000000000 AND LENGTH(TRIM(NEW.reference))>0 AND NEW.reference=TRIM(NEW.reference) AND {$approvalEmpty} AND {$closeEmpty} AND ".
            $open('NEW.requested_on').
            ' AND '.
            $revision('withdrawals', 'void', 'NEW.requested_on', 'closed_on').
            " AND EXISTS (SELECT 1 FROM treasury_bindings s JOIN treasury_bindings d ON d.id=NEW.destination_binding_id JOIN treasury_accounts a ON a.id=s.treasury_account_id JOIN treasury_accounts b ON b.id=d.treasury_account_id WHERE s.id=NEW.source_binding_id AND s.book_id=NEW.book_id AND d.book_id=NEW.book_id AND s.treasury_account_id=NEW.source_account_id AND d.treasury_account_id=NEW.destination_account_id AND a.kind='monetbil' AND b.kind<>'monetbil' AND a.id<>b.id AND s.account_id<>d.account_id AND s.inflow_side=d.inflow_side)";
        $approve =
            "OLD.status='pending' AND NEW.status='approved' AND NEW.version=2 AND NEW.reference_slot=1 AND NEW.approved_on>=NEW.requested_on AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL AND LENGTH(TRIM(NEW.approval_note))>0 AND {$closeEmpty} AND NOT EXISTS (SELECT 1 FROM accounting_books b WHERE b.id=NEW.book_id AND b.mode='live' AND NEW.approved_by=NEW.created_by) AND ".
            $open('NEW.approved_on');
        $sameApproval =
            'NEW.approved_on IS NOT DISTINCT FROM OLD.approved_on AND NEW.approved_by IS NOT DISTINCT FROM OLD.approved_by AND NEW.approved_at IS NOT DISTINCT FROM OLD.approved_at AND NEW.approval_note IS NOT DISTINCT FROM OLD.approval_note';
        $close =
            "OLD.status IN ('pending','approved') AND NEW.version=OLD.version+1 AND {$sameApproval} AND NEW.closed_on IS NOT NULL AND NEW.closed_by IS NOT NULL AND NEW.closed_at IS NOT NULL AND LENGTH(TRIM(NEW.close_note))>0 AND NEW.close_document_id IS NOT NULL AND LENGTH(NEW.close_document_sha256)=64 AND ((NEW.status='cancelled' AND NEW.reference_slot=1 AND ".
            $spent('NEW.id').
            "<NEW.amount) OR (NEW.status='void' AND NEW.reference_slot IS NULL AND ".
            $spent('NEW.id').
            "=0)) AND NOT EXISTS (SELECT 1 FROM accounting_books b WHERE b.id=NEW.book_id AND b.mode='live' AND NEW.closed_by=NEW.created_by) AND ".
            $open('NEW.closed_on').
            ' AND '.
            $chrono('NEW.id', 'NEW.closed_on');
        $receiptInsert =
            "NEW.status='recorded' AND NEW.version=1 AND NEW.reference_slot=1 AND NEW.gross BETWEEN 1 AND 1000000000000000000 AND NEW.net BETWEEN 1 AND NEW.gross AND NEW.fee=NEW.gross-NEW.net AND LENGTH(TRIM(NEW.reference))>0 AND NEW.reference=TRIM(NEW.reference) AND {$reversalEmpty} AND ".
            $open('NEW.posted_on').
            ' AND '.
            $chrono('NEW.withdrawal_id', 'NEW.posted_on').
            ' AND '.
            $revision('withdrawal_receipts', 'reversed', 'NEW.posted_on', 'reversed_on').
            " AND EXISTS (
            SELECT 1 FROM withdrawals w JOIN accounting_entries e ON e.id=NEW.entry_id JOIN accounting_books bk ON bk.id=w.book_id
            JOIN treasury_bindings s ON s.id=NEW.source_binding_id JOIN treasury_bindings d ON d.id=NEW.destination_binding_id
            JOIN accounting_entry_lines a ON a.id=NEW.source_line_id JOIN accounting_entry_lines b ON b.id=NEW.destination_line_id
            WHERE w.id=NEW.withdrawal_id AND w.book_id=NEW.book_id AND (w.status='approved' OR (w.status='cancelled' AND EXISTS (SELECT 1 FROM withdrawal_receipts old_receipt WHERE old_receipt.id=NEW.replaces_id AND old_receipt.withdrawal_id=w.id AND NEW.gross<=old_receipt.gross))) AND w.source_account_id=NEW.source_account_id
            AND s.book_id=w.book_id AND d.book_id=w.book_id AND s.treasury_account_id=w.source_account_id AND d.treasury_account_id=w.destination_account_id AND s.inflow_side=d.inflow_side
            AND e.book_id=w.book_id AND e.status='posted' AND e.source_id IS NULL AND e.reversal_of IS NULL AND e.entry_date=NEW.posted_on AND NEW.received_at>=w.requested_at
            AND a.entry_id=e.id AND b.entry_id=e.id AND a.id<>b.id AND a.account_id=s.account_id AND b.account_id=d.account_id
            AND ((s.inflow_side='debit' AND a.credit=NEW.gross AND a.debit=0 AND b.debit=NEW.net AND b.credit=0) OR (s.inflow_side='credit' AND a.debit=NEW.gross AND a.credit=0 AND b.credit=NEW.net AND b.debit=0))
            AND NOT EXISTS (SELECT 1 FROM accounting_entries z WHERE z.reversal_of=e.id)
            AND NOT EXISTS (SELECT 1 FROM expense_payments z WHERE z.entry_id=e.id)
            AND NOT EXISTS (SELECT 1 FROM accounting_entry_lines l WHERE l.entry_id=e.id AND l.id NOT IN (a.id,b.id) AND ((s.inflow_side='debit' AND l.credit<>0) OR (s.inflow_side='credit' AND l.debit<>0) OR EXISTS (SELECT 1 FROM treasury_bindings x WHERE x.book_id=w.book_id AND x.account_id=l.account_id)))
            AND (bk.mode<>'live' OR (NEW.created_by<>w.created_by AND NEW.created_by<>e.posted_by))
            AND NEW.gross + ".
            $spent('w.id').
            '<=w.amount)';
        $unmatched =
            'NOT EXISTS (SELECT 1 FROM treasury_match_ledger l JOIN treasury_matches m ON m.id=l.match_id WHERE l.ledger_line_id IN (NEW.source_line_id,NEW.destination_line_id) AND l.active_slot=1 AND m.status=\'active\')';
        $receiptUpdate =
            "(OLD.status='recorded' AND NEW.status='reversing' AND NEW.version=1 AND NEW.reference_slot=1 AND {$reversalEmpty} AND {$unmatched}) OR (OLD.status='reversing' AND NEW.status='reversed' AND NEW.version=2 AND NEW.reference_slot IS NULL AND {$unmatched} AND NEW.reversed_on IS NOT NULL AND NEW.reversed_by IS NOT NULL AND NEW.reversed_at IS NOT NULL AND LENGTH(TRIM(NEW.reversal_note))>0 AND NEW.reversal_document_id IS NOT NULL AND LENGTH(NEW.reversal_document_sha256)=64 AND ".
            $open('NEW.reversed_on').
            ' AND '.
            $chrono('NEW.withdrawal_id', 'NEW.reversed_on').
            " AND EXISTS (SELECT 1 FROM accounting_entries e WHERE e.id=NEW.reversal_entry_id AND e.reversal_of=NEW.entry_id AND e.status='posted' AND e.book_id=NEW.book_id AND e.entry_date=NEW.reversed_on))";
        $reverseGuard =
            "NEW.status='posted' AND NEW.reversal_of IS NOT NULL AND EXISTS (SELECT 1 FROM withdrawal_receipts r WHERE (r.entry_id=NEW.reversal_of AND r.status='recorded') OR r.reversal_entry_id=NEW.reversal_of)";
        $mutable = [
            'withdrawals' => [
                'status',
                'version',
                'reference_slot',
                'approved_on',
                'approved_by',
                'approved_at',
                'approval_note',
                'closed_on',
                'closed_by',
                'closed_at',
                'close_note',
                'close_document_id',
                'close_document_sha256',
            ],
            'withdrawal_receipts' => [
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
            DB::unprepared("CREATE OR REPLACE FUNCTION withdrawal_history_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'WITHDRAWAL_IMMUTABLE'; END IF;
                IF (to_jsonb(NEW)-COALESCE(TG_ARGV,ARRAY[]::text[])) IS DISTINCT FROM (to_jsonb(OLD)-COALESCE(TG_ARGV,ARRAY[]::text[])) THEN RAISE EXCEPTION 'WITHDRAWAL_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE OR REPLACE FUNCTION withdrawal_request_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE;
                IF TG_OP='INSERT' THEN IF NOT COALESCE(({$requestInsert}),false) THEN RAISE EXCEPTION 'WITHDRAWAL_STATE'; END IF;
                ELSE IF NOT COALESCE((({$approve}) OR ({$close})),false) THEN RAISE EXCEPTION 'WITHDRAWAL_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE OR REPLACE FUNCTION withdrawal_receipt_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE;
                IF TG_OP='INSERT' THEN IF NOT COALESCE(({$receiptInsert}),false) THEN RAISE EXCEPTION 'WITHDRAWAL_POSTING'; END IF;
                ELSE IF NOT COALESCE(({$receiptUpdate}),false) THEN RAISE EXCEPTION 'WITHDRAWAL_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE OR REPLACE FUNCTION withdrawal_reversal_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$reverseGuard} THEN RAISE EXCEPTION 'WITHDRAWAL_LINKED_REVERSAL'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER withdrawal_reversal_guard BEFORE UPDATE ON accounting_entries FOR EACH ROW EXECUTE FUNCTION withdrawal_reversal_guard();");
            foreach ($mutable as $table => $fields) {
                $function = $table === 'withdrawals' ? 'withdrawal_request_state' : 'withdrawal_receipt_state';
                $args = implode(',', array_map(fn ($v) => "'{$v}'", $fields));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_state BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}(); CREATE TRIGGER {$table}_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION withdrawal_history_guard({$args});",
                );
            }
        } else {
            $close = str_replace('IS NOT DISTINCT FROM', 'IS', $close);
            DB::unprepared("CREATE TRIGGER withdrawal_request_insert BEFORE INSERT ON withdrawals WHEN NOT COALESCE(({$requestInsert} AND typeof(NEW.amount)='integer'),false) BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_STATE'); END;
                CREATE TRIGGER withdrawal_request_update BEFORE UPDATE ON withdrawals WHEN NOT COALESCE((({$approve}) OR ({$close})),false) BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_STATE'); END;
                CREATE TRIGGER withdrawal_receipt_insert BEFORE INSERT ON withdrawal_receipts WHEN NOT COALESCE(({$receiptInsert} AND typeof(NEW.gross)='integer' AND typeof(NEW.net)='integer' AND typeof(NEW.fee)='integer'),false) BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_POSTING'); END;
                CREATE TRIGGER withdrawal_receipt_update BEFORE UPDATE ON withdrawal_receipts WHEN NOT COALESCE(({$receiptUpdate}),false) BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_STATE'); END;
                CREATE TRIGGER withdrawal_reversal_guard BEFORE UPDATE ON accounting_entries WHEN {$reverseGuard} BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_LINKED_REVERSAL'); END;");
            foreach ($mutable as $table => $fields) {
                $immutable = implode(',', array_diff(Schema::getColumnListing($table), $fields));
                DB::unprepared(
                    "CREATE TRIGGER {$table}_history BEFORE UPDATE OF {$immutable} ON {$table} BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_IMMUTABLE'); END; CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'WITHDRAWAL_IMMUTABLE'); END;",
                );
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS withdrawal_reversal_guard'.
                (DB::getDriverName() === 'pgsql' ? ' ON accounting_entries' : ''),
        );
        Schema::dropIfExists('withdrawal_receipts');
        Schema::dropIfExists('withdrawals');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS withdrawal_history_guard(); DROP FUNCTION IF EXISTS withdrawal_request_state(); DROP FUNCTION IF EXISTS withdrawal_receipt_state(); DROP FUNCTION IF EXISTS withdrawal_reversal_guard();',
            );
        }
    }
};
