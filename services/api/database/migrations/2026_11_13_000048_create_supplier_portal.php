<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People of a supplier who may use the portal; access is per supplier, checked on every resource.
        Schema::create('supplier_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('role', 10);
            $t->string('status', 10)->default('active');
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'user_id']);
            $t->index(['user_id', 'status']);
        });
        Schema::create('supplier_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('email_hash', 64);
            $t->string('role', 10);
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'created_at']);
        });
        // What a supplier proposes (new product sheet, change on an offer, profile change); KORA decides.
        Schema::create('supplier_proposals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('kind', 14);
            $t->foreignUuid('shop_id')->nullable()->constrained('shops')->restrictOnDelete();
            $t->foreignUuid('offer_id')->nullable()->constrained('offers')->restrictOnDelete();
            $t->json('payload');
            $t->string('status', 12)->default('submitted');
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignUuid('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('supplier_availability_declarations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignUuid('offer_id')->constrained('offers')->restrictOnDelete();
            $t->unsignedInteger('quantity')->nullable();
            $t->timestamp('valid_until');
            $t->unsignedInteger('preparation_minutes');
            $t->string('note', 500)->nullable();
            $t->string('status', 10)->default('pending');
            $t->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['offer_id', 'created_at']);
        });
        // A supplier's answer to a purchase or a collection: recorded as given, never edited. It informs the team;
        // it does not by itself confirm a purchase, a debt or a receipt.
        Schema::create('supplier_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('subject_type', 16);
            $t->uuid('subject_id');
            $t->string('decision', 10);
            $t->string('reference', 150)->nullable();
            $t->string('note', 1000)->nullable();
            $t->foreignId('responded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id', 'created_at']);
        });
        Schema::create('supplier_shared_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignUuid('document_id')->constrained('private_documents')->restrictOnDelete();
            $t->string('title', 150);
            $t->string('direction', 8);
            $t->foreignId('shared_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'document_id']);
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            // Explicit action of the team: the supplier sees the purchase in its portal from this moment only.
            $t->timestamp('shared_with_supplier_at')->nullable();
        });

        $response =
            "NEW.subject_type IN ('purchase_order','collection') AND NEW.decision IN ('confirmed','declined','ready','issue')";
        $proposal =
            "NEW.kind IN ('product','offer_update','profile') AND NEW.status IN ('submitted','in_review','accepted','rejected','withdrawn') AND (NEW.status NOT IN ('accepted','rejected') OR (NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at IS NOT NULL))";
        $proposalUpdate =
            "OLD.status IN ('submitted','in_review') AND NEW.supplier_id=OLD.supplier_id AND NEW.kind=OLD.kind AND NEW.submitted_by=OLD.submitted_by AND CAST(NEW.payload AS TEXT)=CAST(OLD.payload AS TEXT)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION supplier_responses_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'SUPPLIER_RESPONSE_IMMUTABLE'; END IF; IF NOT COALESCE(({$response}),false) THEN RAISE EXCEPTION 'SUPPLIER_RESPONSE_INVALID'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER supplier_responses_guard BEFORE INSERT OR UPDATE OR DELETE ON supplier_responses FOR EACH ROW EXECUTE FUNCTION supplier_responses_guard();
            CREATE OR REPLACE FUNCTION supplier_proposals_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SUPPLIER_PROPOSAL_IMMUTABLE'; END IF; IF NOT COALESCE(({$proposal}),false) THEN RAISE EXCEPTION 'SUPPLIER_PROPOSAL_INVALID'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$proposalUpdate}),false) THEN RAISE EXCEPTION 'SUPPLIER_PROPOSAL_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER supplier_proposals_guard BEFORE INSERT OR UPDATE OR DELETE ON supplier_proposals FOR EACH ROW EXECUTE FUNCTION supplier_proposals_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER supplier_responses_insert BEFORE INSERT ON supplier_responses WHEN NOT COALESCE(({$response}),0) BEGIN SELECT RAISE(ABORT,'SUPPLIER_RESPONSE_INVALID'); END;
            CREATE TRIGGER supplier_responses_update BEFORE UPDATE ON supplier_responses BEGIN SELECT RAISE(ABORT,'SUPPLIER_RESPONSE_IMMUTABLE'); END;
            CREATE TRIGGER supplier_responses_delete BEFORE DELETE ON supplier_responses BEGIN SELECT RAISE(ABORT,'SUPPLIER_RESPONSE_IMMUTABLE'); END;
            CREATE TRIGGER supplier_proposals_insert BEFORE INSERT ON supplier_proposals WHEN NOT COALESCE(({$proposal}),0) BEGIN SELECT RAISE(ABORT,'SUPPLIER_PROPOSAL_INVALID'); END;
            CREATE TRIGGER supplier_proposals_update BEFORE UPDATE ON supplier_proposals WHEN NOT COALESCE(({$proposal} AND {$proposalUpdate}),0) BEGIN SELECT RAISE(ABORT,'SUPPLIER_PROPOSAL_IMMUTABLE'); END;
            CREATE TRIGGER supplier_proposals_delete BEFORE DELETE ON supplier_proposals BEGIN SELECT RAISE(ABORT,'SUPPLIER_PROPOSAL_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('shared_with_supplier_at'));
        foreach (
            [
                'supplier_shared_documents',
                'supplier_responses',
                'supplier_availability_declarations',
                'supplier_proposals',
                'supplier_invitations',
                'supplier_members',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS supplier_responses_guard(); DROP FUNCTION IF EXISTS supplier_proposals_guard();',
            );
        }
    }
};
