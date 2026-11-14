<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Layouts of the financial statements (income statement, balance sheet), written by the company and
        // examined by its accountant: which accounts feed which line. Nothing is assumed from a chart.
        Schema::create('accounting_statement_layouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('kind', 20);
            $t->unsignedInteger('version');
            $t->json('label');
            $t->json('lines');
            $t->string('sha256', 64);
            $t->unsignedInteger('chart_version');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamp('created_at');
            $t->unique(['book_id', 'kind', 'version']);
        });
        // Each export leaves a trace: who, what, which period, how many rows and the file fingerprint.
        Schema::create('accounting_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->string('report', 24);
            $t->string('format', 6);
            $t->json('parameters');
            $t->unsignedInteger('rows');
            $t->unsignedBigInteger('bytes');
            $t->string('sha256', 64);
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['book_id', 'created_at']);
        });

        $layoutUpdate =
            'OLD.reviewed_at IS NULL AND NEW.reviewed_at IS NOT NULL AND NEW.reviewed_by IS NOT NULL AND NEW.sha256=OLD.sha256 AND CAST(NEW.lines AS TEXT)=CAST(OLD.lines AS TEXT) AND NEW.version=OLD.version AND NEW.book_id=OLD.book_id AND NEW.kind=OLD.kind';
        $layout = "NEW.kind IN ('income_statement','balance_sheet') AND NEW.version>=1";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION accounting_statement_layouts_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'ACCOUNTING_LAYOUT_IMMUTABLE'; END IF; IF NOT COALESCE(({$layout}),false) THEN RAISE EXCEPTION 'ACCOUNTING_LAYOUT_INVALID'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$layoutUpdate}),false) THEN RAISE EXCEPTION 'ACCOUNTING_LAYOUT_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER accounting_statement_layouts_guard BEFORE INSERT OR UPDATE OR DELETE ON accounting_statement_layouts FOR EACH ROW EXECUTE FUNCTION accounting_statement_layouts_guard();
            CREATE OR REPLACE FUNCTION accounting_exports_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'ACCOUNTING_EXPORT_IMMUTABLE'; END \$\$;
            CREATE TRIGGER accounting_exports_guard BEFORE UPDATE OR DELETE ON accounting_exports FOR EACH ROW EXECUTE FUNCTION accounting_exports_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER accounting_statement_layouts_insert BEFORE INSERT ON accounting_statement_layouts WHEN NOT COALESCE(({$layout}),0) BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LAYOUT_INVALID'); END;
            CREATE TRIGGER accounting_statement_layouts_update BEFORE UPDATE ON accounting_statement_layouts WHEN NOT COALESCE(({$layout} AND {$layoutUpdate}),0) BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LAYOUT_IMMUTABLE'); END;
            CREATE TRIGGER accounting_statement_layouts_delete BEFORE DELETE ON accounting_statement_layouts BEGIN SELECT RAISE(ABORT,'ACCOUNTING_LAYOUT_IMMUTABLE'); END;
            CREATE TRIGGER accounting_exports_update BEFORE UPDATE ON accounting_exports BEGIN SELECT RAISE(ABORT,'ACCOUNTING_EXPORT_IMMUTABLE'); END;
            CREATE TRIGGER accounting_exports_delete BEFORE DELETE ON accounting_exports BEGIN SELECT RAISE(ABORT,'ACCOUNTING_EXPORT_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_exports');
        Schema::dropIfExists('accounting_statement_layouts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS accounting_statement_layouts_guard(); DROP FUNCTION IF EXISTS accounting_exports_guard();',
            );
        }
    }
};
