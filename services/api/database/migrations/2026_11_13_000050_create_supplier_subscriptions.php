<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Offers KORA may sell to suppliers: a Standard subscription and optional visibility. Prices are the
        // company's; nothing is seeded and both are off until the owner decides.
        Schema::create('supplier_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 30)->unique();
            $t->string('kind', 12);
            $t->json('name');
            $t->json('description');
            $t->unsignedBigInteger('price');
            $t->unsignedSmallInteger('period_days');
            $t->json('benefits');
            $t->string('status', 10)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('supplier_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained('supplier_plans')->restrictOnDelete();
            // Price, period and benefits as accepted; a later plan change never rewrites them.
            $t->json('plan_snapshot');
            $t->json('offer_ids');
            $t->string('status', 16);
            $t->json('consent')->nullable();
            $t->boolean('renew')->default(false);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
            $t->index(['status', 'ends_on']);
        });
        // Identified payment requests: no recurring debit; a period starts only once its payment is verified.
        Schema::create('supplier_billing_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignUuid('subscription_id')->constrained('supplier_subscriptions')->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->unsignedSmallInteger('period_days');
            $t->string('status', 10)->default('issued');
            $t->string('channel', 20)->nullable();
            $t->string('reference', 150)->nullable();
            $t->foreignUuid('evidence_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['subscription_id', 'status']);
            $t->index(['status', 'created_at']);
        });

        $subscription =
            "NEW.status IN ('proposed','awaiting_payment','active','expired','cancelled') AND (NEW.status IN ('proposed','cancelled') OR NEW.consent IS NOT NULL) AND (NEW.status<>'active' OR (NEW.starts_on IS NOT NULL AND NEW.ends_on IS NOT NULL AND NEW.ends_on>=NEW.starts_on)) AND (NEW.status<>'cancelled' OR NEW.cancelled_at IS NOT NULL)";
        $subscriptionUpdate =
            "OLD.status NOT IN ('expired','cancelled') AND NEW.supplier_id=OLD.supplier_id AND NEW.plan_id=OLD.plan_id AND CAST(NEW.plan_snapshot AS TEXT)=CAST(OLD.plan_snapshot AS TEXT) AND NEW.version>OLD.version";
        // A request is paid once, with its reference and the person who verified it; a paid request stays paid.
        $billing =
            "NEW.status IN ('issued','paid','cancelled') AND (NEW.status<>'paid' OR (NEW.reference IS NOT NULL AND NEW.paid_at IS NOT NULL AND NEW.recorded_by IS NOT NULL))";
        $billingUpdate =
            "OLD.status='issued' AND NEW.amount=OLD.amount AND NEW.subscription_id=OLD.subscription_id AND NEW.version>OLD.version";
        foreach (
            [
                'supplier_subscriptions' => [$subscription, $subscriptionUpdate, 'SUPPLIER_SUBSCRIPTION'],
                'supplier_billing_requests' => [$billing, $billingUpdate, 'SUPPLIER_BILLING'],
            ] as $table => [$insert, $update, $code]
        ) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION '{$code}_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION '{$code}_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION '{$code}_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard();");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'{$code}_STATE'); END;
                CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'{$code}_IMMUTABLE'); END;
                CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'{$code}_IMMUTABLE'); END;");
            }
        }
    }

    public function down(): void
    {
        foreach (['supplier_billing_requests', 'supplier_subscriptions', 'supplier_plans'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS supplier_subscriptions_guard(); DROP FUNCTION IF EXISTS supplier_billing_requests_guard();',
            );
        }
    }
};
