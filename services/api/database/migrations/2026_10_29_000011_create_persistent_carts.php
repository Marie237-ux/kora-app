<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $t->string('guest_owner_hash', 64)->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('merged_into')->nullable();
            $t->timestamp('merged_at')->nullable();
            $t->timestamps();
        });
        Schema::table(
            'carts',
            fn (Blueprint $t) => $t->foreign('merged_into')->references('id')->on('carts')->restrictOnDelete(),
        );
        Schema::create('cart_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('cart_id')->constrained('carts')->cascadeOnDelete();
            $t->foreignUuid('offer_id')->constrained('offers')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->boolean('selected')->default(true);
            $t->json('saved_conditions');
            $t->timestamp('conditions_saved_at');
            $t->timestamps();
            $t->unique(['cart_id', 'offer_id']);
        });
        Schema::create('cart_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('cart_id')->constrained('carts')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->json('lines');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('cart_share_imports', function (Blueprint $t) {
            $t->foreignUuid('cart_id')->constrained('carts')->restrictOnDelete();
            $t->foreignUuid('share_id')->constrained('cart_shares')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->primary(['cart_id', 'share_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE carts ADD CONSTRAINT cart_owner_state CHECK ((merged_into IS NULL AND ((user_id IS NOT NULL AND guest_owner_hash IS NULL) OR (user_id IS NULL AND guest_owner_hash IS NOT NULL))) OR (merged_into IS NOT NULL AND user_id IS NULL AND guest_owner_hash IS NULL))',
            );
            DB::statement(
                'ALTER TABLE cart_lines ADD CONSTRAINT cart_quantity_range CHECK (quantity BETWEEN 1 AND 1000000)',
            );
        }
    }

    public function down(): void
    {
        foreach (['cart_share_imports', 'cart_shares', 'cart_lines', 'carts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
