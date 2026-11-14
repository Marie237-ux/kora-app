<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_books', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('mode', 10);
            $t->unsignedInteger('live_slot')->nullable()->unique();
            $t->json('label');
            $t->json('entity_snapshot');
            $t->unsignedInteger('chart_version')->default(0);
            $t->unsignedInteger('reviewed_chart_version')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('accounting_chart_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->string('reference', 200);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'version']);
        });
        Schema::create('accounting_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('code', 20);
            $t->string('parent_code', 20)->nullable();
            $t->json('label');
            $t->string('kind', 20);
            $t->string('auxiliary_type', 20);
            $t->boolean('postable');
            $t->boolean('active');
            $t->unsignedInteger('chart_version');
            $t->timestamps();
            $t->unique(['book_id', 'code']);
        });
        Schema::create('accounting_journals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('code', 12);
            $t->json('label');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['book_id', 'code']);
        });
        Schema::create('accounting_years', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->json('label');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 12)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('year_id')->constrained('accounting_years')->restrictOnDelete();
            $t->json('label');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 12)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('accounting_sequences', function (Blueprint $t) {
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('year_id')->constrained('accounting_years')->restrictOnDelete();
            $t->foreignUuid('journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $t->unsignedBigInteger('last_number');
            $t->primary(['book_id', 'year_id', 'journal_id']);
        });
        Schema::create('accounting_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('source_type', 40);
            $t->unsignedInteger('version');
            $t->unsignedInteger('chart_version');
            $t->foreignUuid('journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $t->boolean('enabled');
            $t->json('lines');
            $t->string('sha256', 64);
            $t->text('note');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'source_type', 'version']);
        });
        Schema::create('accounting_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('source_type', 40);
            $t->uuid('resource_id');
            $t->timestamp('occurred_at');
            $t->date('event_date');
            $t->json('snapshot');
            $t->string('sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'source_type', 'resource_id']);
        });
        Schema::create('accounting_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $t->foreignUuid('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $t->foreignUuid('year_id')->constrained('accounting_years')->restrictOnDelete();
            $t->foreignUuid('source_id')->nullable()->constrained('accounting_sources')->restrictOnDelete();
            $t->foreignUuid('mapping_id')->nullable()->constrained('accounting_mappings')->restrictOnDelete();
            $t->foreignUuid('reversal_of')->nullable()->unique();
            $t->unsignedInteger('chart_version');
            $t->date('entry_date');
            $t->json('description');
            $t->string('reference', 200);
            $t->json('document_ids');
            $t->string('status', 12)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->string('number', 120)->nullable();
            $t->json('posted_snapshot')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['book_id', 'number']);
            $t->index(['book_id', 'year_id', 'entry_date', 'status']);
        });
        // PostgreSQL requires the primary key to exist before a self-referencing foreign key is added.
        Schema::table('accounting_entries', function (Blueprint $t) {
            $t->foreign('reversal_of')->references('id')->on('accounting_entries')->restrictOnDelete();
        });
        Schema::table('accounting_sources', function (Blueprint $t) {
            $t->foreignUuid('latest_entry_id')->nullable()->constrained('accounting_entries')->restrictOnDelete();
            $t->foreignUuid('posted_entry_id')
                ->nullable()
                ->unique()
                ->constrained('accounting_entries')
                ->restrictOnDelete();
        });
        Schema::create('accounting_entry_lines', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('entry_id')->constrained('accounting_entries')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->foreignUuid('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $t->json('account_snapshot');
            $t->unsignedBigInteger('debit');
            $t->unsignedBigInteger('credit');
            $t->json('party')->nullable();
            $t->unique(['entry_id', 'position']);
        });
        Schema::create('accounting_entry_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('entry_id')->constrained('accounting_entries')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 20);
            $t->json('snapshot');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['entry_id', 'version']);
        });
        DB::statement(
            "CREATE UNIQUE INDEX accounting_one_posted_source ON accounting_entries(source_id) WHERE status = 'posted' AND source_id IS NOT NULL",
        );
        $this->guards();
    }

    private function guards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION accounting_entry_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                  IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'posted' THEN RAISE EXCEPTION 'ACCOUNTING_IMMUTABLE'; END IF;
                    RETURN OLD;
                  END IF;
                  IF TG_OP = 'INSERT' AND NEW.status = 'posted' THEN RAISE EXCEPTION 'ACCOUNTING_POST_REQUIRED'; END IF;
                  IF TG_OP = 'UPDATE' AND OLD.status = 'posted' THEN RAISE EXCEPTION 'ACCOUNTING_IMMUTABLE'; END IF;
                  IF NEW.status = 'posted' THEN
                    IF NEW.number IS NULL OR NEW.posted_snapshot IS NULL OR NEW.sha256 IS NULL
                      OR (SELECT COUNT(DISTINCT account_id) FROM accounting_entry_lines WHERE entry_id = NEW.id) < 2
                      OR (SELECT COALESCE(SUM(debit),0) FROM accounting_entry_lines WHERE entry_id = NEW.id) = 0
                      OR (SELECT SUM(debit) - SUM(credit) FROM accounting_entry_lines WHERE entry_id = NEW.id) <> 0
                    THEN RAISE EXCEPTION 'ACCOUNTING_UNBALANCED'; END IF;
                    IF NOT EXISTS (SELECT 1 FROM accounting_periods p JOIN accounting_years y ON y.id=p.year_id
                      WHERE p.id=NEW.period_id AND p.book_id=NEW.book_id AND p.year_id=NEW.year_id AND p.status='open' AND y.status='open'
                      AND NEW.entry_date BETWEEN p.starts_on AND p.ends_on) THEN RAISE EXCEPTION 'ACCOUNTING_PERIOD_CLOSED'; END IF;
                  END IF;
                  RETURN NEW;
                END $$;
                CREATE TRIGGER accounting_entry_guard_trigger BEFORE INSERT OR UPDATE OR DELETE ON accounting_entries FOR EACH ROW EXECUTE FUNCTION accounting_entry_guard();
                CREATE OR REPLACE FUNCTION accounting_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                  IF TG_OP <> 'INSERT' AND EXISTS(SELECT 1 FROM accounting_entries WHERE id=OLD.entry_id AND status='posted') THEN RAISE EXCEPTION 'ACCOUNTING_IMMUTABLE'; END IF;
                  IF TG_OP <> 'DELETE' THEN
                    IF EXISTS(SELECT 1 FROM accounting_entries WHERE id=NEW.entry_id AND status='posted') THEN RAISE EXCEPTION 'ACCOUNTING_IMMUTABLE'; END IF;
                    IF NEW.debit < 0 OR NEW.credit < 0 OR (NEW.debit > 0 AND NEW.credit > 0) OR (NEW.debit = 0 AND NEW.credit = 0) THEN RAISE EXCEPTION 'ACCOUNTING_LINE_INVALID'; END IF;
                    RETURN NEW;
                  END IF;
                  RETURN OLD;
                END $$;
                CREATE TRIGGER accounting_line_guard_trigger BEFORE INSERT OR UPDATE OR DELETE ON accounting_entry_lines FOR EACH ROW EXECUTE FUNCTION accounting_line_guard();
                SQL
                ,
            );
        } else {
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared(
                    "CREATE TRIGGER accounting_entry_immutable_{$action} BEFORE {$action} ON accounting_entries WHEN OLD.status='posted' BEGIN SELECT RAISE(ABORT, 'ACCOUNTING_IMMUTABLE'); END",
                );
                DB::unprepared(
                    "CREATE TRIGGER accounting_line_immutable_{$action} BEFORE {$action} ON accounting_entry_lines WHEN EXISTS(SELECT 1 FROM accounting_entries WHERE id=OLD.entry_id AND status='posted') BEGIN SELECT RAISE(ABORT, 'ACCOUNTING_IMMUTABLE'); END",
                );
            }
            DB::unprepared(
                "CREATE TRIGGER accounting_no_posted_insert BEFORE INSERT ON accounting_entries WHEN NEW.status='posted' BEGIN SELECT RAISE(ABORT,'ACCOUNTING_POST_REQUIRED'); END",
            );
            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER accounting_post_guard BEFORE UPDATE ON accounting_entries WHEN NEW.status='posted' BEGIN
                  SELECT CASE WHEN NEW.number IS NULL OR NEW.posted_snapshot IS NULL OR NEW.sha256 IS NULL
                    OR (SELECT COUNT(DISTINCT account_id) FROM accounting_entry_lines WHERE entry_id=NEW.id) < 2
                    OR (SELECT COALESCE(SUM(debit),0) FROM accounting_entry_lines WHERE entry_id=NEW.id) = 0
                    OR (SELECT SUM(debit)-SUM(credit) FROM accounting_entry_lines WHERE entry_id=NEW.id) <> 0
                    THEN RAISE(ABORT,'ACCOUNTING_UNBALANCED') END;
                  SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM accounting_periods p JOIN accounting_years y ON y.id=p.year_id
                    WHERE p.id=NEW.period_id AND p.book_id=NEW.book_id AND p.year_id=NEW.year_id AND p.status='open' AND y.status='open'
                    AND NEW.entry_date BETWEEN p.starts_on AND p.ends_on) THEN RAISE(ABORT,'ACCOUNTING_PERIOD_CLOSED') END;
                END;
                SQL
                ,
            );
            foreach (['INSERT', 'UPDATE'] as $action) {
                DB::unprepared(
                    "CREATE TRIGGER accounting_line_guard_{$action} BEFORE {$action} ON accounting_entry_lines BEGIN SELECT CASE WHEN EXISTS(SELECT 1 FROM accounting_entries WHERE id=NEW.entry_id AND status='posted') THEN RAISE(ABORT,'ACCOUNTING_IMMUTABLE') END; SELECT CASE WHEN NEW.debit < 0 OR NEW.credit < 0 OR (NEW.debit>0 AND NEW.credit>0) OR (NEW.debit=0 AND NEW.credit=0) THEN RAISE(ABORT,'ACCOUNTING_LINE_INVALID') END; END",
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS accounting_entry_guard() CASCADE; DROP FUNCTION IF EXISTS accounting_line_guard() CASCADE;',
            );
        }
        Schema::dropIfExists('accounting_entry_revisions');
        Schema::dropIfExists('accounting_entry_lines');
        Schema::dropIfExists('accounting_sequences');
        Schema::table('accounting_sources', function (Blueprint $t) {
            $t->dropConstrainedForeignId('latest_entry_id');
            $t->dropConstrainedForeignId('posted_entry_id');
        });
        foreach (
            [
                'accounting_entries',
                'accounting_sources',
                'accounting_mappings',
                'accounting_periods',
                'accounting_years',
                'accounting_journals',
                'accounting_accounts',
                'accounting_chart_revisions',
                'accounting_books',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
