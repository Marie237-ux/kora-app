<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('incident_id')->nullable()->constrained('payment_incidents')->restrictOnDelete();
            $t->bigInteger('amount');
            $t->string('reason', 300);
            // requested → paid (verified with evidence) or cancelled. A request alone never counts as refunded.
            $t->string('status', 20);
            $t->string('channel', 30)->nullable();
            $t->string('reference', 150)->nullable();
            $t->foreignUuid('evidence_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->string('cancel_reason', 300)->nullable();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['order_id', 'status']);
            // The same transfer cannot prove two refunds.
            $t->unique(['channel', 'reference']);
        });
        // What the provider reported as actually received, even when it differs from what was asked.
        Schema::table('payment_attempts', function (Blueprint $t) {
            $t->bigInteger('received_amount')->nullable();
        });
        Schema::table('payment_incidents', function (Blueprint $t) {
            $t->string('resolution', 40)->nullable();
            $t->text('resolution_note')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_incidents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('resolved_by');
            $t->dropColumn(['resolution', 'resolution_note']);
        });
        Schema::table('payment_attempts', fn (Blueprint $t) => $t->dropColumn('received_amount'));
        Schema::dropIfExists('customer_refunds');
    }
};
