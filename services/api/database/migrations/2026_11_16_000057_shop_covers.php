<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wide cover photo shown at the top of the shop page (public media path); without one the page shows the
        // shop's own products instead of stretching the profile photo.
        Schema::table('shops', function (Blueprint $t) {
            $t->string('cover_path', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->dropColumn('cover_path');
        });
    }
};
