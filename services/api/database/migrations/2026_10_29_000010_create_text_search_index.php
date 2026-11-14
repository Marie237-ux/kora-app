<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogue_search_documents', function (Blueprint $t) {
            $t->foreignUuid('offer_id')->primary()->constrained('offers')->cascadeOnDelete();
            $t->timestamp('indexed_at');
        });
        Schema::create('catalogue_search_tokens', function (Blueprint $t) {
            $t->foreignUuid('offer_id')->constrained('catalogue_search_documents', 'offer_id')->cascadeOnDelete();
            $t->string('token', 50);
            $t->unsignedSmallInteger('weight');
            $t->primary(['offer_id', 'token']);
            $t->index(['token', 'offer_id']);
        });
        Schema::create('search_synonyms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('locale', 2);
            $t->json('terms');
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('search_misses', function (Blueprint $t) {
            $t->date('day');
            $t->string('locale', 2);
            $t->string('query_digest', 64);
            $t->unsignedSmallInteger('token_count');
            $t->unsignedInteger('occurrences')->default(1);
            $t->primary(['day', 'locale', 'query_digest']);
        });
    }

    public function down(): void
    {
        foreach (
            ['search_misses', 'search_synonyms', 'catalogue_search_tokens', 'catalogue_search_documents'] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
