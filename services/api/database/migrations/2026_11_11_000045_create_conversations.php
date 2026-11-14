<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer–support messaging. Messages are numbered per conversation so devices sync after a cursor and
        // resend safely: a repeated client reference returns the stored message instead of a duplicate.
        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 20)->unique();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('subject', 150);
            $t->string('context_type', 12);
            $t->uuid('context_id')->nullable();
            $t->string('status', 10)->default('open');
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('case_id')->nullable()->constrained('after_sales_cases')->restrictOnDelete();
            $t->unsignedInteger('last_seq')->default(0);
            $t->unsignedInteger('customer_read_seq')->default(0);
            $t->unsignedInteger('staff_read_seq')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'updated_at']);
            $t->index(['status', 'updated_at']);
        });
        Schema::create('conversation_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained('conversations')->restrictOnDelete();
            $t->unsignedInteger('seq');
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('side', 8);
            // Internal notes never reach the customer's conversation.
            $t->string('visibility', 8);
            $t->text('body')->nullable();
            $t->foreignUuid('document_id')->nullable()->unique()->constrained('private_documents')->restrictOnDelete();
            $t->string('client_ref', 64);
            $t->timestamp('created_at');
            $t->unique(['conversation_id', 'seq']);
            $t->unique(['conversation_id', 'author_id', 'client_ref']);
        });
        Schema::create('quick_replies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 100);
            $t->json('body');
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        $message =
            "NEW.side IN ('customer','staff') AND NEW.visibility IN ('public','internal') AND (NEW.side='staff' OR NEW.visibility='public') AND NEW.seq>=1 AND (LENGTH(TRIM(COALESCE(NEW.body,'')))>0 OR NEW.document_id IS NOT NULL)";
        $conversation =
            "NEW.context_type IN ('general','order','product','after_sales') AND NEW.status IN ('open','closed') AND (NEW.context_type='general' OR NEW.context_id IS NOT NULL)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION conversation_messages_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'MESSAGE_IMMUTABLE'; END IF; IF NOT COALESCE(({$message}),false) THEN RAISE EXCEPTION 'MESSAGE_STATE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER conversation_messages_guard BEFORE INSERT OR UPDATE OR DELETE ON conversation_messages FOR EACH ROW EXECUTE FUNCTION conversation_messages_guard();
            CREATE OR REPLACE FUNCTION conversations_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'MESSAGE_IMMUTABLE'; END IF; IF NOT COALESCE(({$conversation}),false) THEN RAISE EXCEPTION 'CONVERSATION_STATE'; END IF; IF TG_OP='UPDATE' AND NOT COALESCE((NEW.user_id=OLD.user_id AND NEW.number=OLD.number AND NEW.last_seq>=OLD.last_seq),false) THEN RAISE EXCEPTION 'MESSAGE_IMMUTABLE'; END IF; RETURN NEW; END \$\$;
            CREATE TRIGGER conversations_guard BEFORE INSERT OR UPDATE OR DELETE ON conversations FOR EACH ROW EXECUTE FUNCTION conversations_guard();");
        } else {
            DB::unprepared("CREATE TRIGGER conversation_messages_insert BEFORE INSERT ON conversation_messages WHEN NOT COALESCE(({$message}),0) BEGIN SELECT RAISE(ABORT,'MESSAGE_STATE'); END;
            CREATE TRIGGER conversation_messages_update BEFORE UPDATE ON conversation_messages BEGIN SELECT RAISE(ABORT,'MESSAGE_IMMUTABLE'); END;
            CREATE TRIGGER conversation_messages_delete BEFORE DELETE ON conversation_messages BEGIN SELECT RAISE(ABORT,'MESSAGE_IMMUTABLE'); END;
            CREATE TRIGGER conversations_insert BEFORE INSERT ON conversations WHEN NOT COALESCE(({$conversation}),0) BEGIN SELECT RAISE(ABORT,'CONVERSATION_STATE'); END;
            CREATE TRIGGER conversations_update BEFORE UPDATE ON conversations WHEN NOT COALESCE(({$conversation} AND NEW.user_id=OLD.user_id AND NEW.number=OLD.number AND NEW.last_seq>=OLD.last_seq),0) BEGIN SELECT RAISE(ABORT,'MESSAGE_IMMUTABLE'); END;
            CREATE TRIGGER conversations_delete BEFORE DELETE ON conversations BEGIN SELECT RAISE(ABORT,'MESSAGE_IMMUTABLE'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS conversation_messages_guard(); DROP FUNCTION IF EXISTS conversations_guard();',
            );
        }
    }
};
