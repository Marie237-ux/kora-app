<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('disk', 40);
            $t->string('object_key')->unique();
            $t->string('mime', 40);
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->unsignedInteger('bytes');
            $t->string('sha256', 64);
            $t->json('alt');
            $t->boolean('is_public')->default(false);
            $t->boolean('revoked')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('rights_confirmed_at')->nullable();
            $t->timestamps();
        });
        foreach (['suppliers', 'shops', 'categories', 'products'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        }
        Schema::table('suppliers', fn (Blueprint $t) => $t->string('status')->default('active'));
        foreach (['shops', 'categories', 'products', 'offers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('draft')->nullable();
                $t->unsignedInteger('published_version')->nullable();
            });
        }
        Schema::table('shops', fn (Blueprint $t) => $t->string('status')->default('draft'));
        Schema::table('categories', function (Blueprint $t) {
            $t->boolean('published')->default(false);
            $t->string('status')->default('draft');
            $t->json('attribute_definitions')->nullable();
        });
        DB::table('categories')->update(['published' => true, 'status' => 'published', 'published_version' => 1]);
        DB::table('shops')
            ->where('published', true)
            ->update(['status' => 'published', 'published_version' => 1]);
        Schema::table('variants', fn (Blueprint $t) => $t->boolean('active')->default(true));
        Schema::table('offers', function (Blueprint $t) {
            $t->unsignedInteger('minimum_quantity')->default(1);
            $t->bigInteger('promotion_price')->nullable();
            $t->timestamp('promotion_starts_at')->nullable();
            $t->timestamp('promotion_ends_at')->nullable();
            $t->json('promotion_funding')->nullable();
        });
        Schema::table('purchase_agreements', function (Blueprint $t) {
            $t->string('mode')->default('discount');
            $t->bigInteger('fixed_cost')->nullable();
            $t->timestamp('withdrawn_at')->nullable();
        });
        Schema::create('catalogue_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('entity', 30);
            $t->uuid('resource_id');
            $t->unsignedInteger('version');
            $t->string('action', 30);
            $t->json('snapshot');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['entity', 'resource_id', 'version']);
        });
        Schema::create('slug_redirects', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('entity', 30);
            $t->string('slug');
            $t->uuid('resource_id');
            $t->timestamp('created_at');
            $t->unique(['entity', 'slug']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE offers ADD CONSTRAINT valid_offer_promotion CHECK (minimum_quantity >= 1 AND (promotion_price IS NULL OR (promotion_price >= 0 AND promotion_price < reference_price AND promotion_starts_at IS NOT NULL AND promotion_ends_at > promotion_starts_at)))',
            );
            DB::statement(
                "ALTER TABLE purchase_agreements ADD CONSTRAINT valid_fixed_agreement CHECK ((mode = 'discount' AND fixed_cost IS NULL) OR (mode = 'fixed' AND fixed_cost BETWEEN 0 AND 1000000000000))",
            );
            DB::statement(
                'ALTER TABLE media_assets ADD CONSTRAINT valid_media_dimensions CHECK (width > 0 AND height > 0 AND bytes > 0)',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT valid_offer_promotion');
            DB::statement('ALTER TABLE purchase_agreements DROP CONSTRAINT valid_fixed_agreement');
        }
        foreach (['slug_redirects', 'catalogue_revisions', 'media_assets'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table(
            'purchase_agreements',
            fn (Blueprint $t) => $t->dropColumn(['mode', 'fixed_cost', 'withdrawn_at']),
        );
        Schema::table(
            'offers',
            fn (Blueprint $t) => $t->dropColumn([
                'minimum_quantity',
                'promotion_price',
                'promotion_starts_at',
                'promotion_ends_at',
                'promotion_funding',
            ]),
        );
        Schema::table('variants', fn (Blueprint $t) => $t->dropColumn('active'));
        Schema::table(
            'categories',
            fn (Blueprint $t) => $t->dropColumn(['published', 'status', 'attribute_definitions']),
        );
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('status'));
        foreach (['shops', 'categories', 'products', 'offers'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['draft', 'published_version']));
        }
        foreach (['suppliers', 'shops', 'categories', 'products'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('version'));
        }
    }
};
