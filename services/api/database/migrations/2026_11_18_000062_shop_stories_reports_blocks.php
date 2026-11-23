<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shop stories: a photo (and a line, a product link) shown for 24 hours. Posted by the KORA team for a shop;
        // a story is only seen once published (agent validation), and disappears on its own when it expires.
        Schema::create('shop_stories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $t->uuid('media_id');
            $t->json('caption')->nullable();
            $t->uuid('offer_id')->nullable();
            $t->string('status', 16)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['shop_id', 'status', 'expires_at']);
        });
        // A member reports a shop (or one of its products) to the KORA team; the team reviews it.
        Schema::create('shop_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $t->uuid('offer_id')->nullable();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 32);
            $t->text('details')->nullable();
            $t->string('status', 16)->default('open');
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        // A member blocks a shop: its stories and products stop being shown to them.
        Schema::create('shop_blocks', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $t->timestamp('created_at');
            $t->primary(['user_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_blocks');
        Schema::dropIfExists('shop_reports');
        Schema::dropIfExists('shop_stories');
    }
};
