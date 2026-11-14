<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_books', function (Blueprint $t) {
            $t->boolean('capture_enabled')->default(false);
            $t->date('capture_from')->nullable();
            $t->unsignedInteger('capture_version')->default(1);
        });
        Schema::table('accounting_sources', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by')->nullable()->change();
            $t->foreignUuid('capture_event_id')->nullable()->constrained('outbox_events')->restrictOnDelete();
        });
        Schema::create('accounting_capture_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('book_id')->constrained('accounting_books')->restrictOnDelete();
            $t->foreignUuid('event_id')->constrained('outbox_events')->restrictOnDelete();
            $t->string('source_type', 40)->nullable();
            $t->uuid('resource_id')->nullable();
            $t->date('event_date')->nullable();
            $t->foreignUuid('source_id')->nullable()->constrained('accounting_sources')->restrictOnDelete();
            $t->string('status', 30);
            $t->string('reason', 50)->nullable();
            $t->unsignedInteger('policy_version');
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamps();
            $t->unique(['book_id', 'event_id']);
            $t->index(['book_id', 'source_type', 'resource_id'], 'accounting_capture_resource_idx');
            $t->index(['book_id', 'status', 'next_attempt_at'], 'accounting_capture_work_idx');
        });
        Schema::create('accounting_capture_attempts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('receipt_id')->constrained('accounting_capture_receipts')->restrictOnDelete();
            $t->unsignedInteger('attempt');
            $t->string('status', 30);
            $t->string('reason', 50)->nullable();
            $t->unsignedInteger('policy_version');
            $t->foreignUuid('source_id')->nullable()->constrained('accounting_sources')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['receipt_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_capture_attempts');
        Schema::dropIfExists('accounting_capture_receipts');
        Schema::table('accounting_sources', function (Blueprint $t) {
            $t->dropConstrainedForeignId('capture_event_id');
            // System captures retain a null actor; rollback must not invent a human creator.
        });
        Schema::table(
            'accounting_books',
            fn (Blueprint $t) => $t->dropColumn(['capture_enabled', 'capture_from', 'capture_version']),
        );
    }
};
