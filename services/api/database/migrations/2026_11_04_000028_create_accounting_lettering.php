<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_letterings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $t->string('party_type', 10);
            $t->string('party_key', 100);
            $t->date('matched_on');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 10)->default('building');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->date('voided_on')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_note')->nullable();
            $t->index(['book_id', 'account_id', 'matched_on']);
        });
        Schema::create('accounting_lettering_lines', function (Blueprint $t) {
            $t->foreignUuid('lettering_id')->constrained('accounting_letterings')->restrictOnDelete();
            $t->foreignId('ledger_line_id')->constrained('accounting_entry_lines')->restrictOnDelete();
            $t->string('side', 6);
            $t->unsignedBigInteger('amount');
            $t->primary(['lettering_id', 'ledger_line_id']);
            $t->index('ledger_line_id');
        });
        $open = fn (
            $date,
        ) => "EXISTS (SELECT 1 FROM accounting_periods p JOIN accounting_years y ON y.id=p.year_id WHERE p.book_id=NEW.book_id AND p.status='open' AND y.status='open' AND p.starts_on<={$date} AND p.ends_on>={$date})";
        $chronological = fn (
            $date,
        ) => "NOT EXISTS (SELECT 1 FROM accounting_letterings g WHERE g.book_id=NEW.book_id AND (g.matched_on>{$date} OR g.voided_on>{$date}))";
        $emptyVoid =
            'NEW.voided_on IS NULL AND NEW.voided_by IS NULL AND NEW.voided_at IS NULL AND NEW.void_note IS NULL';
        $insert =
            "NEW.status='building' AND NEW.version=1 AND {$emptyVoid} AND NEW.party_type IN ('customer','supplier') AND EXISTS (SELECT 1 FROM accounting_accounts a WHERE a.id=NEW.account_id AND a.book_id=NEW.book_id) AND ".
            $open('NEW.matched_on').
            ' AND '.
            $chronological('NEW.matched_on');
        $activate =
            "OLD.status='building' AND NEW.status='active' AND NEW.version=1 AND {$emptyVoid} AND ".
            $open('NEW.matched_on').
            ' AND '.
            $chronological('NEW.matched_on').
            "
            AND (SELECT COUNT(*) FROM accounting_lettering_lines m WHERE m.lettering_id=NEW.id) BETWEEN 2 AND 200
            AND (SELECT COALESCE(SUM(amount),0) FROM accounting_lettering_lines m WHERE m.lettering_id=NEW.id AND side='debit') BETWEEN 1 AND 1000000000000000000
            AND (SELECT COALESCE(SUM(CASE WHEN side='debit' THEN amount ELSE -amount END),0) FROM accounting_lettering_lines m WHERE m.lettering_id=NEW.id)=0
            AND NOT EXISTS (SELECT 1 FROM accounting_lettering_lines m JOIN accounting_entry_lines l ON l.id=m.ledger_line_id WHERE m.lettering_id=NEW.id AND m.amount + COALESCE((SELECT SUM(x.amount) FROM accounting_lettering_lines x JOIN accounting_letterings g ON g.id=x.lettering_id WHERE x.ledger_line_id=m.ledger_line_id AND g.status='active'),0) > l.debit+l.credit)";
        $void =
            "OLD.status='active' AND NEW.status='void' AND OLD.version=1 AND NEW.version=2 AND NEW.voided_on IS NOT NULL AND NEW.voided_by IS NOT NULL AND NEW.voided_at IS NOT NULL AND NEW.void_note IS NOT NULL AND LENGTH(TRIM(NEW.void_note))>0 AND NEW.voided_on>=NEW.matched_on AND ".
            $open('NEW.voided_on').
            ' AND '.
            $chronological('NEW.voided_on');
        $member = "EXISTS (SELECT 1 FROM accounting_letterings g JOIN accounting_entry_lines l ON l.id=NEW.ledger_line_id JOIN accounting_entries e ON e.id=l.entry_id
            WHERE g.id=NEW.lettering_id AND g.status='building' AND e.status='posted' AND e.book_id=g.book_id AND l.account_id=g.account_id
            AND e.entry_date<=g.matched_on AND NEW.amount BETWEEN 1 AND 1000000000000000000 AND NEW.amount<=l.debit+l.credit
            AND ((NEW.side='debit' AND l.debit>0) OR (NEW.side='credit' AND l.credit>0))
            AND {party_type}=g.party_type AND {party_id}=g.party_key AND {auxiliary}=g.party_type)";
        $mutable = ['status', 'version', 'voided_on', 'voided_by', 'voided_at', 'void_note'];
        if (DB::getDriverName() === 'pgsql') {
            $member = strtr($member, [
                '{party_type}' => "l.party->>'type'",
                '{party_id}' => "l.party->>'id'",
                '{auxiliary}' => "l.account_snapshot->>'auxiliary_type'",
            ]);
            $keys = implode(',', array_map(fn ($f) => "'{$f}'", $mutable));
            DB::unprepared("CREATE OR REPLACE FUNCTION accounting_lettering_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'ACCOUNTING_LETTERING_IMMUTABLE'; END IF;
                PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE;
                IF TG_OP='INSERT' THEN IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION 'ACCOUNTING_LETTERING_STATE'; END IF;
                ELSE
                    IF (to_jsonb(NEW)-ARRAY[{$keys}]) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY[{$keys}]) THEN RAISE EXCEPTION 'ACCOUNTING_LETTERING_IMMUTABLE'; END IF;
                    IF NOT COALESCE((({$activate}) OR ({$void})),false) THEN RAISE EXCEPTION 'ACCOUNTING_LETTERING_STATE'; END IF;
                END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER accounting_lettering_state BEFORE INSERT OR UPDATE OR DELETE ON accounting_letterings FOR EACH ROW EXECUTE FUNCTION accounting_lettering_state();
                CREATE OR REPLACE FUNCTION accounting_lettering_member() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'ACCOUNTING_LETTERING_IMMUTABLE'; END IF;
                    PERFORM 1 FROM accounting_books b JOIN accounting_letterings g ON g.book_id=b.id WHERE g.id=NEW.lettering_id FOR UPDATE OF b;
                    IF NOT COALESCE(({$member}),false) THEN RAISE EXCEPTION 'ACCOUNTING_LETTERING_SCOPE'; END IF;
                    RETURN NEW; END \$\$;
                CREATE TRIGGER accounting_lettering_member BEFORE INSERT OR UPDATE OR DELETE ON accounting_lettering_lines FOR EACH ROW EXECUTE FUNCTION accounting_lettering_member();");
        } else {
            $member = "({$member}) AND typeof(NEW.amount)='integer'";
            $member = strtr($member, [
                '{party_type}' => "json_extract(l.party,'$.type')",
                '{party_id}' => "json_extract(l.party,'$.id')",
                '{auxiliary}' => "json_extract(l.account_snapshot,'$.auxiliary_type')",
            ]);
            $immutable = implode(',', array_diff(Schema::getColumnListing('accounting_letterings'), $mutable));
            DB::unprepared("CREATE TRIGGER accounting_lettering_insert BEFORE INSERT ON accounting_letterings WHEN NOT COALESCE(({$insert}),false) BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_STATE'); END;
                CREATE TRIGGER accounting_lettering_state BEFORE UPDATE ON accounting_letterings WHEN NOT COALESCE((({$activate}) OR ({$void})),false) BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_STATE'); END;
                CREATE TRIGGER accounting_lettering_history BEFORE UPDATE OF {$immutable} ON accounting_letterings BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_IMMUTABLE'); END;
                CREATE TRIGGER accounting_lettering_delete BEFORE DELETE ON accounting_letterings BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_IMMUTABLE'); END;
                CREATE TRIGGER accounting_lettering_member_insert BEFORE INSERT ON accounting_lettering_lines WHEN NOT COALESCE(({$member}),false) BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_SCOPE'); END;
                CREATE TRIGGER accounting_lettering_member_update BEFORE UPDATE ON accounting_lettering_lines BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_IMMUTABLE'); END;
                CREATE TRIGGER accounting_lettering_member_delete BEFORE DELETE ON accounting_lettering_lines BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LETTERING_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_lettering_lines');
        Schema::dropIfExists('accounting_letterings');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS accounting_lettering_state(); DROP FUNCTION IF EXISTS accounting_lettering_member();',
            );
        }
    }
};
