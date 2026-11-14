<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a courier actually goes: district and landmark as people give directions in Douala, and optional
        // coordinates. The map stays optional; coordinates only refine the order of stops when they are known.
        Schema::table('collection_points', function (Blueprint $t) {
            $t->string('district', 120)->nullable();
            $t->string('landmark', 300)->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collection_points', function (Blueprint $t) {
            $t->dropColumn(['district', 'landmark', 'latitude', 'longitude']);
        });
    }
};
