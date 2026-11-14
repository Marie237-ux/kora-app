<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('title', 150);
            $t->text('description');
            $t->string('kind', 20)->default('collection');
            $t->string('visibility', 20)->default('private');
            $t->unsignedInteger('version')->default(1);
            $t->date('gift_date')->nullable();
            $t->boolean('hide_gift_purchases')->default(true);
            $t->uuid('cover_pin_id')->nullable();
            $t->string('share_token_hash', 64)->nullable()->unique();
            $t->timestamp('share_expires_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['owner_id', 'archived_at', 'updated_at']);
            $t->index(['visibility', 'archived_at']);
        });
        Schema::create('board_sections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('board_id')->constrained('boards')->restrictOnDelete();
            $t->string('title', 100);
            $t->unsignedInteger('position');
            $t->timestamps();
            $t->index(['board_id', 'position']);
        });
        Schema::create('board_pins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('board_id')->constrained('boards')->restrictOnDelete();
            $t->foreignUuid('section_id')->nullable()->constrained('board_sections')->nullOnDelete();
            $t->foreignUuid('offer_id')->constrained('offers')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->unsignedInteger('wished_quantity')->default(1);
            $t->text('caption');
            $t->json('saved_offer');
            $t->bigInteger('saved_unit_price');
            $t->timestamp('price_saved_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['board_id', 'offer_id']);
            $t->index(['board_id', 'section_id', 'position']);
        });
        Schema::table(
            'boards',
            fn (Blueprint $t) => $t->foreign('cover_pin_id')->references('id')->on('board_pins')->nullOnDelete(),
        );
        Schema::create('board_pin_notes', function (Blueprint $t) {
            $t->foreignUuid('pin_id')->constrained('board_pins')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->primary(['pin_id', 'user_id']);
        });
        Schema::create('board_members', function (Blueprint $t) {
            $t->foreignUuid('board_id')->constrained('boards')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('role', 20);
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->primary(['board_id', 'user_id']);
        });
        Schema::create('board_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('board_id')->constrained('boards')->restrictOnDelete();
            $t->string('email_hash', 64);
            $t->string('recipient_hint', 200);
            $t->string('token_hash', 64)->unique();
            $t->string('role', 20);
            $t->timestamp('expires_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['board_id', 'email_hash']);
        });
        Schema::create('board_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('board_id')->constrained('boards')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->json('data');
            $t->timestamp('created_at');
            $t->unique(['board_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('boards', fn (Blueprint $t) => $t->dropForeign(['cover_pin_id']));
        foreach (
            [
                'board_events',
                'board_invitations',
                'board_members',
                'board_pin_notes',
                'board_pins',
                'board_sections',
                'boards',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
