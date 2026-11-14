<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_balance_observations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->foreignUuid('binding_id')->constrained('treasury_bindings')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('reference', 200);
            $t->bigInteger('reported_balance');
            $t->unsignedBigInteger('available_funds')->nullable();
            $t->unsignedBigInteger('withdrawable_funds')->nullable();
            $t->timestamp('observed_at');
            $t->timestamp('expires_at');
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->string('document_sha256', 64);
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('status', 12)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_note')->nullable();
            $t->foreignUuid('void_document_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->string('void_document_sha256', 64)->nullable();
            $t->unique(['book_id', 'treasury_account_id', 'sequence'], 'balance_observation_sequence');
        });
        $latest =
            'NOT EXISTS (SELECT 1 FROM treasury_balance_observations n WHERE n.book_id=NEW.book_id AND n.treasury_account_id=NEW.treasury_account_id AND n.sequence>NEW.sequence)';
        $expiry =
            DB::getDriverName() === 'pgsql'
                ? 'EXTRACT(EPOCH FROM (NEW.expires_at-NEW.observed_at))<=86400'
                : '(julianday(NEW.expires_at)-julianday(NEW.observed_at))*86400<=86400.001';
        $emptyReview = 'NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL AND NEW.review_note IS NULL';
        $emptyVoid =
            'NEW.voided_by IS NULL AND NEW.voided_at IS NULL AND NEW.void_note IS NULL AND NEW.void_document_id IS NULL AND NEW.void_document_sha256 IS NULL';
        $scope =
            "EXISTS (SELECT 1 FROM treasury_bindings b JOIN treasury_accounts a ON a.id=b.treasury_account_id WHERE b.id=NEW.binding_id AND b.book_id=NEW.book_id AND a.id=NEW.treasury_account_id AND ((a.kind='monetbil' AND NEW.reported_balance>=0 AND NEW.available_funds IS NULL AND NEW.withdrawable_funds BETWEEN 0 AND NEW.reported_balance) OR (a.kind<>'monetbil' AND NEW.withdrawable_funds IS NULL AND NEW.available_funds BETWEEN 0 AND CASE WHEN NEW.reported_balance>0 THEN NEW.reported_balance ELSE 0 END)))";
        $insert = "NEW.status='pending' AND NEW.version=1 AND {$emptyReview} AND {$emptyVoid} AND {$scope} AND NEW.reported_balance BETWEEN -1000000000000000000 AND 1000000000000000000 AND NEW.expires_at>NEW.observed_at AND {$expiry} AND NEW.created_at>=NEW.observed_at AND LENGTH(TRIM(NEW.reference))>0 AND LENGTH(NEW.document_sha256)=64 AND NEW.sequence=COALESCE((SELECT MAX(h.sequence) FROM treasury_balance_observations h WHERE h.book_id=NEW.book_id AND h.treasury_account_id=NEW.treasury_account_id),0)+1 AND NOT EXISTS (SELECT 1 FROM treasury_balance_observations h WHERE h.book_id=NEW.book_id AND h.treasury_account_id=NEW.treasury_account_id AND h.observed_at>NEW.observed_at)";
        $separate = fn (
            $actor,
        ) => "NOT EXISTS (SELECT 1 FROM accounting_books b WHERE b.id=NEW.book_id AND b.mode='live' AND NEW.{$actor}=NEW.created_by)";
        $sameReview =
            'NEW.reviewed_by IS NOT DISTINCT FROM OLD.reviewed_by AND NEW.reviewed_at IS NOT DISTINCT FROM OLD.reviewed_at AND NEW.review_note IS NOT DISTINCT FROM OLD.review_note';
        $update =
            "((OLD.status='pending' AND NEW.status='approved' AND NEW.version=2 AND {$latest} AND {$emptyVoid} AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at>=NEW.created_at AND NEW.reviewed_at<NEW.expires_at AND LENGTH(TRIM(NEW.review_note))>0 AND ".
            $separate('reviewed_by').
            ") OR (OLD.status IN ('pending','approved') AND NEW.status='void' AND NEW.version=OLD.version+1 AND {$sameReview} AND NEW.voided_by IS NOT NULL AND NEW.voided_at>=NEW.created_at AND NEW.voided_at>=COALESCE(NEW.reviewed_at,NEW.created_at) AND LENGTH(TRIM(NEW.void_note))>0 AND NEW.void_document_id IS NOT NULL AND LENGTH(NEW.void_document_sha256)=64 AND ".
            $separate('voided_by').
            '))';
        $mutable = [
            'status',
            'version',
            'reviewed_by',
            'reviewed_at',
            'review_note',
            'voided_by',
            'voided_at',
            'void_note',
            'void_document_id',
            'void_document_sha256',
        ];
        $usable =
            "EXISTS (SELECT 1 FROM treasury_bindings b JOIN treasury_accounts a ON a.id=b.treasury_account_id JOIN accounting_accounts g ON g.id=b.account_id JOIN accounting_books k ON k.id=b.book_id WHERE b.id=NEW.binding_id AND b.book_id=NEW.book_id AND a.id=NEW.treasury_account_id AND a.active=true AND g.active=true AND g.postable=true AND b.chart_version=k.chart_version AND (k.mode<>'live' OR (b.reviewed_by IS NOT NULL AND k.reviewed_chart_version=k.chart_version)) AND NOT EXISTS (SELECT 1 FROM treasury_bindings z WHERE z.book_id=b.book_id AND z.treasury_account_id=b.treasury_account_id AND z.version>b.version))";
        $clean = fn (
            $prefix,
        ) => "EXISTS (SELECT 1 FROM private_documents d WHERE d.id=NEW.{$prefix}document_id AND d.sha256=NEW.{$prefix}document_sha256 AND d.scan_status='clean' AND d.purpose='accounting_evidence' AND d.supplier_id IS NULL)";
        $insert .= ' AND '.$usable.' AND '.$clean('');
        $update =
            '('.
            $update.
            ") AND ((NEW.status='approved' AND {$usable} AND ".
            $clean('').
            ") OR (NEW.status='void' AND ".
            $clean('void_').
            '))';
        if (DB::getDriverName() === 'pgsql') {
            $args = implode(',', array_map(fn ($s) => "'{$s}'", $mutable));
            DB::unprepared("CREATE OR REPLACE FUNCTION balance_observation_state() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                PERFORM 1 FROM accounting_books WHERE id=NEW.book_id FOR UPDATE;
                IF TG_OP='INSERT' THEN IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION 'BALANCE_OBSERVATION_INVALID'; END IF;
                ELSE IF NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'BALANCE_OBSERVATION_STATE'; END IF; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER balance_observation_state BEFORE INSERT OR UPDATE ON treasury_balance_observations FOR EACH ROW EXECUTE FUNCTION balance_observation_state();
                CREATE TRIGGER balance_observation_history BEFORE UPDATE OR DELETE ON treasury_balance_observations FOR EACH ROW EXECUTE FUNCTION treasury_history_guard({$args});");
        } else {
            $fields = implode(',', array_diff(Schema::getColumnListing('treasury_balance_observations'), $mutable));
            $update = str_replace('IS NOT DISTINCT FROM', 'IS', $update);
            DB::unprepared("CREATE TRIGGER balance_observation_insert BEFORE INSERT ON treasury_balance_observations WHEN NOT COALESCE(({$insert} AND typeof(NEW.reported_balance)='integer' AND (NEW.available_funds IS NULL OR typeof(NEW.available_funds)='integer') AND (NEW.withdrawable_funds IS NULL OR typeof(NEW.withdrawable_funds)='integer')),false) BEGIN SELECT RAISE(ABORT,'BALANCE_OBSERVATION_INVALID'); END;
                CREATE TRIGGER balance_observation_state BEFORE UPDATE ON treasury_balance_observations WHEN NOT COALESCE(({$update}),false) BEGIN SELECT RAISE(ABORT,'BALANCE_OBSERVATION_STATE'); END;
                CREATE TRIGGER balance_observation_history BEFORE UPDATE OF {$fields} ON treasury_balance_observations BEGIN SELECT RAISE(ABORT,'BALANCE_OBSERVATION_IMMUTABLE'); END;
                CREATE TRIGGER balance_observation_delete BEFORE DELETE ON treasury_balance_observations BEGIN SELECT RAISE(ABORT,'BALANCE_OBSERVATION_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_balance_observations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS balance_observation_state()');
        }
    }
};
