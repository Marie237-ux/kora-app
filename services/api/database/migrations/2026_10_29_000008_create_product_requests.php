<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_command_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('owner_hash', 64);
            $t->string('command', 100);
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['owner_hash', 'command', 'request_key'], 'session_command_unique');
        });
        Schema::create('product_request_groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 200);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('product_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 40)->unique();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('guest_owner_hash', 64)->nullable()->index();
            $t->string('locale', 2);
            $t->json('details');
            $t->string('state', 30)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->string('priority', 20)->default('normal');
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('group_id')->nullable()->constrained('product_request_groups')->restrictOnDelete();
            $t->foreignUuid('linked_offer_id')->nullable()->constrained('offers')->restrictOnDelete();
            $t->foreignUuid('draft_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->boolean('is_alternative')->default(false);
            $t->timestamp('alternative_accepted_at')->nullable();
            $t->text('resolution')->nullable();
            $t->timestamp('draft_expires_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'state', 'created_at']);
            $t->index(['state', 'assignee_id', 'priority']);
            $t->index('linked_offer_id');
        });
        Schema::create('product_request_photos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('product_requests')->restrictOnDelete();
            $t->string('disk', 40);
            $t->string('object_key')->unique();
            $t->unsignedInteger('bytes');
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->string('sha256', 64);
            $t->boolean('revoked')->default(false);
            $t->timestamps();
            $t->unique(['request_id', 'sha256']);
        });
        Schema::create('product_request_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('product_requests')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('author_kind', 20);
            $t->string('visibility', 20);
            $t->string('locale', 2);
            $t->text('body');
            $t->timestamp('created_at');
            $t->index(['request_id', 'visibility', 'created_at']);
        });
        Schema::create('product_request_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('request_id')->constrained('product_requests')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 60);
            $t->string('visibility', 20);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('data');
            $t->timestamp('created_at');
            $t->unique(['request_id', 'version']);
        });
        Schema::create('product_request_candidates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('product_requests')->restrictOnDelete();
            $t->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->foreignUuid('offer_id')->nullable()->constrained('offers')->restrictOnDelete();
            $t->bigInteger('quoted_amount')->nullable();
            $t->text('notes');
            $t->string('reference', 150)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('account_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('event_id')->unique()->constrained('outbox_events')->restrictOnDelete();
            $t->string('kind', 50);
            $t->foreignUuid('request_id')->constrained('product_requests')->restrictOnDelete();
            $t->foreignUuid('offer_id')->nullable()->constrained('offers')->restrictOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'kind', 'request_id', 'offer_id'], 'account_request_notice_unique');
            $t->index(['user_id', 'read_at', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE product_requests ADD CONSTRAINT request_state_owner CHECK ((state = 'draft' AND (user_id IS NOT NULL OR guest_owner_hash IS NOT NULL)) OR (state <> 'draft' AND user_id IS NOT NULL))",
            );
            DB::statement(
                'ALTER TABLE product_request_candidates ADD CONSTRAINT valid_request_quote CHECK (quoted_amount IS NULL OR quoted_amount BETWEEN 0 AND 1000000000000000000)',
            );
        }
    }

    public function down(): void
    {
        foreach (
            [
                'account_notifications',
                'product_request_candidates',
                'product_request_events',
                'product_request_messages',
                'product_request_photos',
                'product_requests',
                'product_request_groups',
                'session_command_receipts',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
