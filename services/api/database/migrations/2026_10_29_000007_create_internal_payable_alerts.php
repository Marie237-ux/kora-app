<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('deduplication_key', 150)->unique();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('obligation_id')->nullable()->constrained('supplier_obligations')->restrictOnDelete();
            $t->foreignUuid('installment_id')->nullable()->constrained('payable_schedule')->restrictOnDelete();
            $t->foreignUuid('contract_id')->nullable()->constrained('supply_contracts')->restrictOnDelete();
            $t->string('kind', 40);
            $t->string('severity', 30);
            $t->string('state', 20);
            $t->bigInteger('amount');
            $t->date('due_on')->nullable();
            $t->json('context');
            $t->unsignedInteger('cycle')->default(1);
            $t->timestamp('first_detected_at');
            $t->timestamp('last_checked_at');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('last_queued_at')->nullable();
            $t->string('last_queued_severity', 30)->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'state']);
            $t->index(['state', 'due_on']);
        });
        Schema::table('outbox_events', function (Blueprint $t) {
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->string('last_error_code', 40)->nullable();
            $t->index(['type', 'processed_at', 'failed_at', 'next_attempt_at'], 'outbox_work_idx');
        });
        Schema::create('outbox_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained('outbox_events')->restrictOnDelete();
            $t->unsignedInteger('attempt');
            $t->string('handler', 80);
            $t->string('result', 30);
            $t->string('error_code', 40)->nullable();
            $t->timestamp('created_at');
            $t->unique(['event_id', 'attempt']);
        });
        Schema::create('operational_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->unique()->constrained('outbox_events')->restrictOnDelete();
            $t->foreignUuid('alert_id')->constrained('operational_alerts')->restrictOnDelete();
            $t->string('audience_permission', 80);
            $t->string('kind', 40);
            $t->timestamp('created_at');
            $t->index(['audience_permission', 'created_at']);
        });
        Schema::create('operational_notification_reads', function (Blueprint $t) {
            $t->foreignUuid('notification_id')->constrained('operational_notifications')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->primary(['notification_id', 'user_id']);
        });
        Schema::table(
            'audit_events',
            fn (Blueprint $t) => $t->index(['action', 'resource_id', 'id'], 'audit_resource_sequence_idx'),
        );
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE operational_alerts ADD CONSTRAINT valid_operational_alert CHECK (amount BETWEEN 0 AND 1000000000000000000 AND cycle > 0 AND state IN ('active', 'resolved'))",
            );
        }
    }

    public function down(): void
    {
        Schema::table('audit_events', fn (Blueprint $t) => $t->dropIndex('audit_resource_sequence_idx'));
        Schema::dropIfExists('operational_notification_reads');
        Schema::dropIfExists('operational_notifications');
        Schema::dropIfExists('outbox_attempts');
        Schema::table('outbox_events', function (Blueprint $t) {
            $t->dropIndex('outbox_work_idx');
            $t->dropColumn(['attempts', 'consecutive_failures', 'next_attempt_at', 'failed_at', 'last_error_code']);
        });
        Schema::dropIfExists('operational_alerts');
    }
};
