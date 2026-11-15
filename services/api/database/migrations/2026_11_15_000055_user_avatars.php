<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer profile photo (public media path), shown next to the customer's published reviews.
        Schema::table('users', function (Blueprint $t) {
            $t->string('avatar_path', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('avatar_path');
        });
    }
};
