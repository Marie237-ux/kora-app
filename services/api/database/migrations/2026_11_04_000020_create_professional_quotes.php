<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->json('billing_identity');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('professional_members', function (Blueprint $t) {
            $t->foreignUuid('profile_id')->constrained('professional_profiles')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('role', 20);
            $t->timestamp('created_at');
            $t->primary(['profile_id', 'user_id']);
            $t->index('user_id');
        });
        Schema::create('professional_addresses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('profile_id')->constrained('professional_profiles')->restrictOnDelete();
            $t->json('address');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('professional_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('profile_id')->constrained('professional_profiles')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('number', 50)->nullable()->unique();
            $t->json('requested_lines');
            $t->json('address_snapshot');
            $t->text('request_notes');
            $t->string('status', 20)->default('requested');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->text('decline_reason')->nullable();
            $t->foreignUuid('order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $t->timestamps();
            $t->index(['profile_id', 'created_at']);
        });
        Schema::create('professional_quote_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('quote_id')->constrained('professional_quotes')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->json('snapshot');
            $t->json('internal_snapshot');
            $t->string('snapshot_sha256', 64);
            $t->timestamp('valid_until');
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['quote_id', 'revision']);
        });
        Schema::table(
            'professional_quotes',
            fn (Blueprint $t) => $t
                ->foreignUuid('current_revision_id')
                ->nullable()
                ->constrained('professional_quote_revisions')
                ->restrictOnDelete(),
        );
        Schema::create('professional_quote_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('quote_id')->constrained('professional_quotes')->restrictOnDelete();
            $t->string('action', 40);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('data');
            $t->timestamp('created_at');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignUuid('professional_quote_revision_id')
                ->nullable()
                ->unique()
                ->constrained('professional_quote_revisions')
                ->restrictOnDelete();
            $t->json('professional_billing_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('professional_quote_revision_id');
            $t->dropColumn('professional_billing_snapshot');
        });
        Schema::dropIfExists('professional_quote_events');
        Schema::table('professional_quotes', fn (Blueprint $t) => $t->dropConstrainedForeignId('current_revision_id'));
        Schema::dropIfExists('professional_quote_revisions');
        Schema::dropIfExists('professional_quotes');
        Schema::dropIfExists('professional_addresses');
        Schema::dropIfExists('professional_members');
        Schema::dropIfExists('professional_profiles');
    }
};
