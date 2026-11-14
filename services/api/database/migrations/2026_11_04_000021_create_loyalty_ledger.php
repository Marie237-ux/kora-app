<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_program', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('enabled')->default(false);
            $t->json('rules')->nullable();
            $t->unsignedBigInteger('committed_points')->default(0);
            $t->timestamps();
        });
        DB::table('loyalty_program')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('loyalty_program_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('version')->unique();
            $t->boolean('enabled');
            $t->json('rules');
            $t->string('sha256', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $t->string('card_number', 40)->unique();
            $t->string('qr_hash', 64)->unique();
            $t->text('qr_encrypted');
            $t->string('status', 20)->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('loyalty_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $t->foreignUuid('order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $t->string('status', 30);
            $t->unsignedInteger('program_version');
            $t->unsignedBigInteger('points');
            $t->unsignedBigInteger('reversed_points')->default(0);
            $t->unsignedBigInteger('eligible_amount')->default(0);
            $t->unsignedBigInteger('returned_amount')->default(0);
            $t->unsignedBigInteger('award_base_amount')->default(0);
            $t->unsignedBigInteger('pre_award_returned_amount')->default(0);
            $t->json('rules_snapshot');
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('available_at');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'status', 'available_at']);
        });
        Schema::create('loyalty_movements', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $t->foreignUuid('grant_id')->nullable()->constrained('loyalty_grants')->restrictOnDelete();
            $t->foreignUuid('refund_id')->nullable()->constrained('customer_refunds')->restrictOnDelete();
            $t->string('kind', 40);
            $t->bigInteger('points_delta');
            $t->bigInteger('pending_delta')->default(0);
            $t->json('explanation');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['account_id', 'id']);
        });
        Schema::create('loyalty_refund_lines', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('refund_id')->constrained('customer_refunds')->restrictOnDelete();
            $t->foreignUuid('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('amount');
            $t->json('explanation');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['refund_id', 'order_line_id']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->json('loyalty_snapshot')->nullable();
            // Written only by the future verified fulfilment workflow, never by a loyalty HTTP endpoint.
            $t->timestamp('delivered_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['loyalty_snapshot', 'delivered_at']));
        foreach (
            [
                'loyalty_refund_lines',
                'loyalty_movements',
                'loyalty_grants',
                'loyalty_accounts',
                'loyalty_program_revisions',
                'loyalty_program',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
