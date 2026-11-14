<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minimal technical journal of AI calls: feature, units and estimated cost; never prompts or images.
        Schema::create('ai_usage', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('feature', 30);
            $t->string('provider', 20);
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 10);
            $t->string('reason', 30)->nullable();
            $t->unsignedInteger('input_units')->default(0);
            $t->unsignedInteger('output_units')->default(0);
            $t->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $t->unsignedInteger('duration_ms')->default(0);
            $t->timestamp('created_at');
            $t->index('created_at');
            $t->index(['user_id', 'feature', 'created_at']);
        });
        // Visual and text vectors of published offers (normalised float32, base64). Results are always re-read
        // through the public catalogue filters, so a stale vector never makes an archived offer purchasable.
        Schema::create('offer_embeddings', function (Blueprint $t) {
            $t->foreignUuid('offer_id')->constrained('offers')->cascadeOnDelete();
            $t->string('kind', 8);
            $t->string('model', 80);
            $t->unsignedSmallInteger('dimensions');
            $t->text('vector');
            $t->string('source_hash', 64);
            $t->timestamp('indexed_at');
            $t->primary(['offer_id', 'kind']);
        });
        Schema::create('embedding_queue', function (Blueprint $t) {
            $t->foreignUuid('offer_id')->primary()->constrained('offers')->cascadeOnDelete();
            $t->string('reason', 20);
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('available_at');
            $t->string('last_error', 40)->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();
            $t->index(['failed_at', 'available_at']);
        });
        // A photo search keeps its normalised image privately for a short time, so the customer can refine it or
        // turn it into a product request without sending it again.
        Schema::create('photo_searches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('guest_owner_hash', 64)->nullable();
            $t->string('disk', 40);
            $t->string('object_key')->unique();
            $t->string('sha256', 64);
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->unsignedInteger('bytes');
            $t->text('query_vector')->nullable();
            $t->string('model', 80)->nullable();
            $t->string('status', 12);
            $t->string('level', 8)->nullable();
            $t->json('results');
            $t->foreignUuid('product_request_id')->nullable()->constrained('product_requests')->nullOnDelete();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
            $t->index(['guest_owner_hash', 'created_at']);
            $t->index('expires_at');
        });
        // Product form assistant: a proposal is kept as given; only a person applies chosen fields to the draft.
        Schema::create('ai_suggestions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->unsignedInteger('product_version');
            $t->json('fields');
            $t->json('proposal');
            $t->string('status', 10)->default('proposed');
            $t->string('model', 80);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('applied_fields')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'created_at']);
        });

        $insert =
            "NEW.status IN ('proposed','applied','discarded') AND (NEW.status<>'applied' OR (NEW.applied_by IS NOT NULL AND NEW.applied_at IS NOT NULL AND NEW.applied_fields IS NOT NULL))";
        $update =
            "OLD.status='proposed' AND NEW.product_id=OLD.product_id AND NEW.requested_by=OLD.requested_by AND CAST(NEW.proposal AS TEXT)=CAST(OLD.proposal AS TEXT)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION ai_suggestions_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'AI_SUGGESTION_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION 'AI_SUGGESTION_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'AI_SUGGESTION_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER ai_suggestions_guard BEFORE INSERT OR UPDATE OR DELETE ON ai_suggestions FOR EACH ROW EXECUTE FUNCTION ai_suggestions_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER ai_suggestions_insert BEFORE INSERT ON ai_suggestions WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'AI_SUGGESTION_STATE'); END;
            CREATE TRIGGER ai_suggestions_update BEFORE UPDATE ON ai_suggestions WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'AI_SUGGESTION_IMMUTABLE'); END;
            CREATE TRIGGER ai_suggestions_delete BEFORE DELETE ON ai_suggestions BEGIN SELECT RAISE(ABORT,'AI_SUGGESTION_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        foreach (['ai_suggestions', 'photo_searches', 'embedding_queue', 'offer_embeddings', 'ai_usage'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ai_suggestions_guard();');
        }
    }
};
