<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_scenes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->json('draft');
            $t->json('publication')->nullable();
            $t->boolean('paused')->default(false);
            $t->integer('position')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('hero_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('hero_scene_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['hero_scene_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_revisions');
        Schema::dropIfExists('hero_scenes');
    }
};
