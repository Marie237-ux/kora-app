<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The heart on products pins to the member's private "Favourites" board: an ordinary board, flagged, one per account.
        Schema::table('boards', function (Blueprint $t) {
            $t->boolean('is_favorites')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX boards_one_favorites_per_owner ON boards (owner_id) WHERE is_favorites');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS boards_one_favorites_per_owner');
        Schema::table('boards', function (Blueprint $t) {
            $t->dropColumn('is_favorites');
        });
    }
};
