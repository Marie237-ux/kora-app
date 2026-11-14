<?php

namespace App\Domain\Shared;

use Illuminate\Support\Facades\DB;

/**
 * Server-side numbering for orders and customer documents. The sequence row is locked and incremented in the
 * caller's transaction, so numbers are unique and, among committed documents, have no gaps or reuse.
 */
final class DocumentNumbers
{
    public function next(string $key, string $prefix, int $digits = 6): string
    {
        $sequence = $this->lock($key);
        $next = (int) $sequence->last_number + 1;
        DB::table('document_sequences')
            ->where('key', $key)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return $prefix.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    }

    /** Lets a composed checkout acquire the sequence before taking catalogue locks. */
    public function lock(string $key): object
    {
        DB::table('document_sequences')->insertOrIgnore([
            'key' => $key,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
    }
}
