<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a shop is, as customers filter it: city and district (quartier), editable in the back office.
        Schema::table('shops', function (Blueprint $t) {
            $t->string('city', 80)->default('Douala');
            $t->string('district', 120)->nullable();
            $t->index(['city', 'district']);
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->dropIndex(['city', 'district']);
            $t->dropColumn(['city', 'district']);
        });
    }
};
