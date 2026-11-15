<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wholesale packaging of an offer: sold by carton / lot / box…, N pieces each, at least M units per order.
        // All null: the offer is not sold in bulk.
        Schema::table('offers', function (Blueprint $t) {
            $t->string('bulk_unit', 20)->nullable();
            $t->unsignedInteger('bulk_unit_size')->nullable();
            $t->unsignedInteger('bulk_min_units')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn(['bulk_unit', 'bulk_unit_size', 'bulk_min_units']);
        });
    }
};
