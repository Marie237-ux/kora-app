<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_command_receipts', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable()->index();
        });
        DB::table('session_command_receipts')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('session_command_receipts')
                        ->where('id', $row->id)
                        ->update(['expires_at' => CarbonImmutable::parse($row->created_at)->addDays(7)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('session_command_receipts', fn (Blueprint $t) => $t->dropColumn('expires_at'));
    }
};
