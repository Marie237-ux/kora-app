<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // KORA delivers in several cities: a zone (its districts) belongs to one city. Existing zones are Douala's.
        Schema::table('delivery_zones', function (Blueprint $t) {
            $t->string('city', 80)->default('Douala');
            $t->index(['city', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_zones', function (Blueprint $t) {
            $t->dropIndex(['city', 'active']);
            $t->dropColumn('city');
        });
    }
};
