<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's favourite shops (the heart on shop cards and shop pages). Following and stories will come later.
        Schema::create('shop_likes', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $t->timestamp('created_at');
            $t->primary(['user_id', 'shop_id']);
            $t->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_likes');
    }
};
