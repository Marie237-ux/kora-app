<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A delivery address is in a city: what comes from a shop in that city is delivered by KORA couriers,
        // what comes from another city is shipped. Existing addresses are in Douala.
        Schema::table('customer_addresses', function (Blueprint $t) {
            $t->string('city', 80)->default('Douala');
        });
        // Shipping between cities: flat fee and delay per origin and destination ("*" = any other city),
        // set by the team (carrier rates); the quote uses the exact route first, then the "*" one.
        Schema::create('shipping_routes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('origin_city', 80);
            $t->string('destination_city', 80);
            $t->unsignedInteger('fee');
            $t->unsignedSmallInteger('days_min');
            $t->unsignedSmallInteger('days_max');
            $t->boolean('to_door')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['origin_city', 'destination_city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_routes');
        Schema::table('customer_addresses', function (Blueprint $t) {
            $t->dropColumn('city');
        });
    }
};
