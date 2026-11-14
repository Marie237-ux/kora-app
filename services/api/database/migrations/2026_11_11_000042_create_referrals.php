<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-level referral: the sponsor earns on the referee's first qualifying purchases (paid, delivered, net
        // of returns), never on the sign-up alone. Rates, caps, threshold, delay and budget are set by the owner;
        // nothing is active by default. Rewards are payable cash claims, kept apart from loyalty points.
        Schema::create('referral_programs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('version')->unique();
            $t->string('status', 10)->default('draft');
            $t->unsignedInteger('rate_bp');
            $t->unsignedBigInteger('purchase_cap');
            $t->unsignedTinyInteger('purchases');
            $t->unsignedBigInteger('payout_threshold');
            $t->unsignedSmallInteger('validation_days');
            $t->unsignedBigInteger('budget');
            $t->json('terms');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            $t->timestamps();
        });
        DB::unprepared(
            "CREATE UNIQUE INDEX referral_programs_one_active ON referral_programs (status) WHERE status='active'",
        );
        Schema::create('referral_codes', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $t->string('code', 16)->unique();
            $t->timestamp('created_at');
        });
        Schema::create('referrals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            // One sponsor per customer, ever.
            $t->foreignId('referee_id')->unique()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('program_id')->constrained('referral_programs')->restrictOnDelete();
            // Signals for a human review, never an automatic proof of fraud (a shared address or network is common).
            $t->json('signals');
            $t->string('status', 10)->default('active');
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['referrer_id', 'created_at']);
            $t->index(['status']);
        });
        Schema::create('referral_rewards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('referral_id')->constrained('referrals')->restrictOnDelete();
            $t->foreignUuid('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $t->unsignedTinyInteger('sequence');
            $t->unsignedBigInteger('base_amount');
            $t->unsignedBigInteger('amount');
            $t->string('status', 12)->default('pending');
            $t->timestamp('available_at');
            $t->timestamp('settled_at')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
            $t->unique(['referral_id', 'sequence']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('referral_payouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('channel', 20);
            $t->string('destination', 40);
            $t->string('status', 12)->default('requested');
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignUuid('evidence_id')->nullable()->constrained('private_documents')->restrictOnDelete();
            $t->string('reference', 150)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });

        $program =
            "NEW.status IN ('draft','active','retired') AND NEW.rate_bp BETWEEN 1 AND 10000 AND NEW.purchases BETWEEN 1 AND 5 AND NEW.purchase_cap>=1 AND NEW.payout_threshold>=1 AND NEW.budget>=1";
        $programUpdate =
            "OLD.version=NEW.version AND OLD.rate_bp=NEW.rate_bp AND OLD.purchase_cap=NEW.purchase_cap AND OLD.purchases=NEW.purchases AND OLD.payout_threshold=NEW.payout_threshold AND OLD.validation_days=NEW.validation_days AND OLD.budget=NEW.budget AND ((OLD.status='draft' AND NEW.status='active' AND NEW.activated_at IS NOT NULL) OR (OLD.status='active' AND NEW.status='retired' AND NEW.retired_at IS NOT NULL))";
        $referral = "NEW.referrer_id<>NEW.referee_id AND NEW.status IN ('active','flagged','blocked')";
        $referralUpdate =
            'NEW.referrer_id=OLD.referrer_id AND NEW.referee_id=OLD.referee_id AND NEW.program_id=OLD.program_id AND NEW.version>OLD.version';
        $reward =
            "NEW.sequence>=1 AND NEW.status IN ('pending','validated','cancelled','exhausted') AND NEW.amount<=NEW.base_amount";
        $rewardUpdate =
            "OLD.status='pending' AND NEW.status IN ('validated','cancelled','exhausted') AND NEW.settled_at IS NOT NULL AND NEW.referral_id=OLD.referral_id AND NEW.order_id=OLD.order_id AND NEW.sequence=OLD.sequence";
        $payout =
            "NEW.amount>=1 AND NEW.channel IN ('mobile_money','bank_transfer','cash') AND NEW.status IN ('requested','approved','paid','rejected','cancelled') AND (NEW.status<>'paid' OR (NEW.evidence_id IS NOT NULL AND NEW.paid_at IS NOT NULL AND NEW.paid_by IS NOT NULL AND LENGTH(TRIM(COALESCE(NEW.reference,'')))>0)) AND (NEW.status NOT IN ('approved','rejected') OR NEW.reviewed_by IS NOT NULL)";
        $payoutUpdate =
            "OLD.status NOT IN ('paid','rejected','cancelled') AND NEW.version=OLD.version+1 AND NEW.user_id=OLD.user_id AND NEW.amount=OLD.amount AND NEW.channel=OLD.channel AND NEW.destination=OLD.destination";
        $guards = [
            'referral_programs' => [$program, $programUpdate, 'REFERRAL_PROGRAM'],
            'referrals' => [$referral, $referralUpdate, 'REFERRAL_STATE'],
            'referral_rewards' => [$reward, $rewardUpdate, 'REFERRAL_REWARD'],
            'referral_payouts' => [$payout, $payoutUpdate, 'REFERRAL_PAYOUT'],
        ];
        foreach ($guards as $table => [$insert, $update, $code]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'REFERRAL_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION '{$code}'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'REFERRAL_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard();");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'{$code}'); END;
                CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'REFERRAL_IMMUTABLE'); END;
                CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'REFERRAL_IMMUTABLE'); END;");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION referral_codes_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'REFERRAL_IMMUTABLE'; END \$\$;
            CREATE TRIGGER referral_codes_guard BEFORE UPDATE OR DELETE ON referral_codes FOR EACH ROW EXECUTE FUNCTION referral_codes_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER referral_codes_update BEFORE UPDATE ON referral_codes BEGIN SELECT RAISE(ABORT,'REFERRAL_IMMUTABLE'); END;
            CREATE TRIGGER referral_codes_delete BEFORE DELETE ON referral_codes BEGIN SELECT RAISE(ABORT,'REFERRAL_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        foreach (
            ['referral_payouts', 'referral_rewards', 'referrals', 'referral_codes', 'referral_programs'] as $table
        ) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS referral_programs_guard(); DROP FUNCTION IF EXISTS referrals_guard(); DROP FUNCTION IF EXISTS referral_rewards_guard(); DROP FUNCTION IF EXISTS referral_payouts_guard(); DROP FUNCTION IF EXISTS referral_codes_guard();',
            );
        }
    }
};
