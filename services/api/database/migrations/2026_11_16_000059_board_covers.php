<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cover photo chosen by the board's owner (public media path): the header of a public board and its card.
        Schema::table('boards', function (Blueprint $t) {
            $t->string('cover_path', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $t) {
            $t->dropColumn('cover_path');
        });
    }
};
