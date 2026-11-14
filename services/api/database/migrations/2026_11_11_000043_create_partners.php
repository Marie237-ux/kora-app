<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Applications to become a partner, followed by the team up to a supplier and shop without duplicates, and
        // the public partner page listing only real, published partners (an honest invitation when there are none).
        Schema::create('partner_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->string('contact_name', 150);
            $t->string('business_name', 150);
            $t->text('activity');
            $t->string('phone', 40);
            $t->string('phone_key', 40)->index();
            $t->string('email', 254)->nullable();
            $t->string('city', 120);
            $t->string('district', 120)->nullable();
            $t->json('product_types');
            $t->text('message')->nullable();
            $t->string('consent_version', 40);
            $t->timestamp('consent_at');
            $t->string('locale', 2);
            $t->uuid('duplicate_of')->nullable();
            $t->string('status', 12)->default('submitted');
            $t->text('decision_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->foreignUuid('shop_id')->nullable()->constrained('shops')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        // Self-reference added once the primary key exists (PostgreSQL refuses it inside the same CREATE).
        Schema::table('partner_applications', function (Blueprint $t) {
            $t->foreign('duplicate_of')->references('id')->on('partner_applications')->restrictOnDelete();
        });
        DB::unprepared(
            "CREATE UNIQUE INDEX partner_applications_one_conversion ON partner_applications (supplier_id) WHERE status='converted'",
        );
        Schema::create('partner_application_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('application_id')->constrained('partner_applications')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->text('body')->nullable();
            $t->foreignUuid('document_id')->nullable()->unique()->constrained('private_documents')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['application_id', 'created_at']);
        });
        Schema::create('partners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('application_id')
                ->nullable()
                ->unique()
                ->constrained('partner_applications')
                ->restrictOnDelete();
            $t->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->foreignUuid('shop_id')->nullable()->constrained('shops')->restrictOnDelete();
            $t->string('name', 150);
            $t->string('slug', 120)->unique();
            $t->json('domain');
            $t->json('presentation');
            $t->json('advantages');
            $t->foreignUuid('logo_media_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $t->string('website', 300)->nullable();
            $t->string('status', 10)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'name']);
        });
        DB::unprepared(
            'CREATE UNIQUE INDEX partners_one_per_supplier ON partners (supplier_id) WHERE supplier_id IS NOT NULL',
        );

        $application =
            "NEW.status IN ('submitted','in_review','accepted','rejected','converted') AND LENGTH(TRIM(NEW.consent_version))>0 AND NEW.consent_at IS NOT NULL AND NEW.locale IN ('fr','en') AND (NEW.status<>'converted' OR NEW.supplier_id IS NOT NULL) AND (NEW.status NOT IN ('accepted','rejected','converted') OR (NEW.decided_by IS NOT NULL AND NEW.decided_at IS NOT NULL))";
        // What the applicant sent is never rewritten; a rejected or converted application is closed.
        $applicationUpdate =
            "OLD.status NOT IN ('rejected','converted') AND NEW.version>OLD.version AND NEW.number=OLD.number AND NEW.contact_name=OLD.contact_name AND NEW.business_name=OLD.business_name AND NEW.activity=OLD.activity AND NEW.phone=OLD.phone AND NEW.consent_version=OLD.consent_version AND NEW.consent_at=OLD.consent_at";
        $partner =
            "NEW.status IN ('draft','published','archived') AND LENGTH(TRIM(NEW.name))>0 AND (NEW.status<>'published' OR NEW.published_at IS NOT NULL)";
        $partnerUpdate = 'NEW.version>OLD.version';
        $note = "LENGTH(TRIM(COALESCE(NEW.body,'')))>0 OR NEW.document_id IS NOT NULL";
        foreach (
            [
                'partner_applications' => [$application, $applicationUpdate, 'PARTNER_APPLICATION'],
                'partners' => [$partner, $partnerUpdate, 'PARTNER_STATE'],
            ] as $table => [$insert, $update, $code]
        ) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'PARTNER_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION '{$code}'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'PARTNER_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard();");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'{$code}'); END;
                CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'PARTNER_IMMUTABLE'); END;
                CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'PARTNER_IMMUTABLE'); END;");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION partner_application_notes_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PARTNER_IMMUTABLE'; END IF; IF NOT COALESCE(({$note}),false) THEN RAISE EXCEPTION 'PARTNER_NOTE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER partner_application_notes_guard BEFORE INSERT OR UPDATE OR DELETE ON partner_application_notes FOR EACH ROW EXECUTE FUNCTION partner_application_notes_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER partner_application_notes_insert BEFORE INSERT ON partner_application_notes WHEN NOT COALESCE(({$note}),0) BEGIN SELECT RAISE(ABORT,'PARTNER_NOTE'); END;
            CREATE TRIGGER partner_application_notes_update BEFORE UPDATE ON partner_application_notes BEGIN SELECT RAISE(ABORT,'PARTNER_IMMUTABLE'); END;
            CREATE TRIGGER partner_application_notes_delete BEFORE DELETE ON partner_application_notes BEGIN SELECT RAISE(ABORT,'PARTNER_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
        Schema::dropIfExists('partner_application_notes');
        Schema::dropIfExists('partner_applications');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS partner_applications_guard(); DROP FUNCTION IF EXISTS partners_guard(); DROP FUNCTION IF EXISTS partner_application_notes_guard();',
            );
        }
    }
};
