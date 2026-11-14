<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reviews of verified delivered purchases, moderated for abuse or personal data, never for being negative.
        Schema::create('product_reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_line_id')->unique()->constrained('order_lines')->restrictOnDelete();
            $t->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->string('title', 150)->nullable();
            $t->text('body');
            $t->string('locale', 2);
            $t->string('status', 10)->default('pending');
            $t->string('rejection_reason', 20)->nullable();
            $t->foreignId('moderated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('moderated_at')->nullable();
            $t->text('reply')->nullable();
            $t->foreignId('replied_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('replied_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['product_id', 'status', 'created_at']);
        });
        Schema::create('review_photos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('review_id')->constrained('product_reviews')->restrictOnDelete();
            $t->foreignUuid('media_id')->unique()->constrained('media_assets')->restrictOnDelete();
            $t->string('status', 10)->default('pending');
            $t->timestamp('created_at');
        });
        Schema::create('review_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('review_id')->constrained('product_reviews')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 20);
            $t->text('note')->nullable();
            $t->string('status', 10)->default('open');
            $t->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('handled_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['review_id', 'user_id']);
        });
        // Price and availability alerts: one-shot, they notify once and stop.
        Schema::create('product_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('offer_id')->constrained('offers')->restrictOnDelete();
            $t->string('kind', 14);
            $t->unsignedBigInteger('baseline_price');
            $t->unsignedBigInteger('target_price')->nullable();
            $t->string('status', 10)->default('active');
            $t->timestamp('triggered_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'offer_id']);
        });
        DB::unprepared(
            "CREATE UNIQUE INDEX product_alerts_one_active ON product_alerts (user_id, offer_id, kind) WHERE status='active'",
        );
        Schema::create('product_views', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->timestamp('viewed_at');
            $t->primary(['user_id', 'product_id']);
            $t->index(['user_id', 'viewed_at']);
        });
        // Notification centre: rendered in the user's language, with a link to a resource they may open.
        Schema::create('user_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('category', 14);
            $t->string('kind', 40);
            $t->string('dedupe_key', 120);
            $t->string('locale', 2);
            $t->string('title', 200);
            $t->text('body');
            $t->string('link', 300)->nullable();
            // Sensitive content never shows in a public push preview.
            $t->boolean('sensitive')->default(false);
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'dedupe_key']);
            $t->index(['user_id', 'read_at', 'created_at']);
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('notification_id')->constrained('user_notifications')->restrictOnDelete();
            $t->string('channel', 12);
            // Honest states: nothing is shown as sent unless a provider really accepted it.
            $t->string('status', 24);
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'channel']);
        });
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('channel', 12);
            $t->string('category', 14);
            $t->boolean('enabled');
            $t->timestamp('updated_at');
            $t->primary(['user_id', 'channel', 'category']);
        });
        Schema::create('web_push_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('endpoint_hash', 64)->unique();
            $t->text('endpoint');
            $t->json('keys');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        $review =
            "NEW.rating BETWEEN 1 AND 5 AND LENGTH(TRIM(NEW.body))>0 AND NEW.status IN ('pending','published','rejected') AND (NEW.status<>'rejected' OR NEW.rejection_reason IN ('abusive','personal_data','off_topic','spam','not_about_product')) AND (NEW.status='pending' OR (NEW.moderated_by IS NOT NULL AND NEW.moderated_at IS NOT NULL)) AND (NEW.reply IS NULL OR (NEW.replied_by IS NOT NULL AND NEW.replied_at IS NOT NULL))";
        $reviewUpdate =
            'NEW.version>OLD.version AND NEW.user_id=OLD.user_id AND NEW.order_line_id=OLD.order_line_id AND NEW.product_id=OLD.product_id';
        $delivery =
            "NEW.channel IN ('in_app','email','web_push','whatsapp') AND NEW.status IN ('pending','sent','failed','skipped_preference','skipped_not_configured') AND (NEW.status<>'sent' OR NEW.sent_at IS NOT NULL)";
        foreach (
            [
                'product_reviews' => [$review, $reviewUpdate, 'REVIEW_STATE'],
                'notification_deliveries' => [
                    $delivery,
                    "NEW.notification_id=OLD.notification_id AND NEW.channel=OLD.channel AND OLD.status NOT IN ('sent')",
                    'DELIVERY_STATE',
                ],
            ] as $table => [$insert, $update, $code]
        ) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'RECORD_IMMUTABLE'; END IF; IF NOT COALESCE(({$insert}),false) THEN RAISE EXCEPTION '{$code}'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE(({$update}),false) THEN RAISE EXCEPTION 'RECORD_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard();");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN NOT COALESCE(({$insert}),0) BEGIN SELECT RAISE(ABORT,'{$code}'); END;
                CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN NOT COALESCE(({$insert} AND {$update}),0) BEGIN SELECT RAISE(ABORT,'RECORD_IMMUTABLE'); END;
                CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'RECORD_IMMUTABLE'); END;");
            }
        }
    }

    public function down(): void
    {
        foreach (
            [
                'web_push_subscriptions',
                'notification_preferences',
                'notification_deliveries',
                'user_notifications',
                'product_views',
                'product_alerts',
                'review_reports',
                'review_photos',
                'product_reviews',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS product_reviews_guard(); DROP FUNCTION IF EXISTS notification_deliveries_guard();',
            );
        }
    }
};
