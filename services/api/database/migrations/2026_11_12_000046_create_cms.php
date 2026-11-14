<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Site settings and pages are versioned documents: drafts, scheduled publication, rollback by publishing
        // an earlier version again. Content is structured data checked against approved shapes, never HTML or code.
        Schema::create('cms_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 12);
            $t->string('slug', 120);
            $t->json('title');
            $t->timestamps();
            $t->unique(['kind', 'slug']);
        });
        Schema::create('cms_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('document_id')->constrained('cms_documents')->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->json('content');
            $t->string('status', 10)->default('draft');
            $t->timestamp('publish_at')->nullable();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('publisher_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->string('preview_token', 64)->unique();
            $t->timestamps();
            $t->unique(['document_id', 'number']);
            $t->index(['document_id', 'status', 'publish_at']);
        });
        // Event calendar: period in Africa/Douala, explicit priority, targeted pages and theme variations.
        Schema::create('cms_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 150);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedSmallInteger('priority');
            $t->json('locales');
            $t->json('pages');
            $t->json('theme');
            $t->json('banner');
            $t->json('product_ids');
            $t->foreignUuid('delivery_offer_id')->nullable()->constrained('delivery_offers')->restrictOnDelete();
            $t->string('status', 10)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'starts_at', 'ends_at']);
        });
        // Legal conditions: versions with effect date and both languages; past acceptances are never rewritten.
        Schema::create('legal_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 20);
            $t->unsignedInteger('number');
            $t->json('body');
            $t->timestamp('effective_at');
            $t->boolean('requires_acceptance')->default(false);
            $t->string('status', 10)->default('draft');
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('publisher_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'number']);
        });
        Schema::create('legal_acceptances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('version_id')->constrained('legal_versions')->restrictOnDelete();
            $t->string('context', 12);
            $t->foreignUuid('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $t->timestamp('accepted_at');
            $t->unique(['user_id', 'version_id', 'context', 'order_id']);
        });
        Schema::table('orders', function (Blueprint $t) {
            // Terms of sale in force when the order was placed.
            $t->foreignUuid('terms_version_id')->nullable()->constrained('legal_versions')->restrictOnDelete();
        });
        Schema::create('cms_revisions', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedBigInteger('revision');
            $t->timestamp('updated_at');
        });
        DB::table('cms_revisions')->insert(['id' => 1, 'revision' => 1, 'updated_at' => now()]);

        $version =
            "NEW.status IN ('draft','scheduled','published','archived') AND NEW.number>=1 AND (NEW.status NOT IN ('scheduled','published') OR NEW.publisher_id IS NOT NULL) AND (NEW.status<>'scheduled' OR NEW.publish_at IS NOT NULL)";
        // Content of a version is frozen once it left the draft state.
        $versionUpdate =
            "NEW.document_id=OLD.document_id AND NEW.number=OLD.number AND NEW.author_id=OLD.author_id AND (OLD.status='draft' OR CAST(NEW.content AS TEXT)=CAST(OLD.content AS TEXT))";
        $legal =
            "NEW.kind IN ('terms_of_use','terms_of_sale','privacy','returns') AND NEW.status IN ('draft','published') AND (NEW.status<>'published' OR (NEW.publisher_id IS NOT NULL AND NEW.published_at IS NOT NULL))";
        $legalUpdate =
            "OLD.status='draft' AND NEW.kind=OLD.kind AND NEW.number=OLD.number AND NEW.author_id=OLD.author_id";
        foreach (
            [
                'cms_versions' => [$version, $versionUpdate, 'CMS_VERSION'],
                'legal_versions' => [$legal, $legalUpdate, 'LEGAL_VERSION'],
            ] as $table => [$insert, $update, $code]
        ) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'CMS_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION '{$code}'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'CMS_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard();");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'{$code}'); END;
                CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'CMS_IMMUTABLE'); END;
                CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'CMS_IMMUTABLE'); END;");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION legal_acceptances_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'CMS_IMMUTABLE'; END \$\$;
            CREATE TRIGGER legal_acceptances_guard BEFORE UPDATE OR DELETE ON legal_acceptances FOR EACH ROW EXECUTE FUNCTION legal_acceptances_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER legal_acceptances_update BEFORE UPDATE ON legal_acceptances BEGIN SELECT RAISE(ABORT,'CMS_IMMUTABLE'); END;
            CREATE TRIGGER legal_acceptances_delete BEFORE DELETE ON legal_acceptances BEGIN SELECT RAISE(ABORT,'CMS_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('terms_version_id'));
        foreach (
            ['cms_revisions', 'legal_acceptances', 'legal_versions', 'cms_campaigns', 'cms_versions', 'cms_documents'] as $table
        ) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS cms_versions_guard(); DROP FUNCTION IF EXISTS legal_versions_guard(); DROP FUNCTION IF EXISTS legal_acceptances_guard();',
            );
        }
    }
};
