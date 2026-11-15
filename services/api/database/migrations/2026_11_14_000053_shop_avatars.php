<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shop's profile photo (public media path, like an offer image), editable in the back office.
        Schema::table('shops', function (Blueprint $t) {
            $t->string('avatar_path', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->dropColumn('avatar_path');
        });
    }
};
