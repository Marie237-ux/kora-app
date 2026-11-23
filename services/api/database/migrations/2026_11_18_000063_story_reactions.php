<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's reaction to a story (one emoji from a short fixed list, one reaction per member and story,
        // changed or removed at will). It disappears with the story.
        Schema::create('story_reactions', function (Blueprint $t) {
            $t->foreignUuid('story_id')->constrained('shop_stories')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('reaction', 16);
            $t->timestamps();
            $t->primary(['story_id', 'user_id']);
            $t->index(['story_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_reactions');
    }
};
