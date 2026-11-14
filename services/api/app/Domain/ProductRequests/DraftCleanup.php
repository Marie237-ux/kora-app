<?php

namespace App\Domain\ProductRequests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DraftCleanup
{
    public function run(int $limit): array
    {
        // Remove cached private responses while retaining the command fingerprint as a replay tombstone.
        $redacted = DB::table('session_command_receipts')
            ->where('expires_at', '<=', now())
            ->whereNotNull('result')
            ->update(['result' => null, 'updated_at' => now()]);
        $counts = [
            'drafts_deleted' => 0,
            'photos_deleted' => 0,
            'storage_failures' => 0,
            'receipts_redacted' => $redacted,
        ];
        $ids = DB::table('product_requests')
            ->where('state', 'draft')
            ->where('draft_expires_at', '<=', now())
            ->orderBy('draft_expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($ids as $id) {
            $photos = DB::transaction(function () use ($id) {
                $r = DB::table('product_requests')->where('id', $id)->lockForUpdate()->first();
                if (! $r || $r->state !== 'draft' || ! $r->draft_expires_at || now()->lessThan($r->draft_expires_at)) {
                    return null;
                }
                DB::table('product_request_photos')
                    ->where('request_id', $id)
                    ->update(['revoked' => true, 'updated_at' => now()]);

                return DB::table('product_request_photos')->where('request_id', $id)->get();
            }, 3);
            if ($photos === null) {
                continue;
            }
            // Storage I/O never holds a database lock. Failed deletions retain their record for the next run.
            foreach ($photos as $p) {
                try {
                    $disk = Storage::disk($p->disk);
                    if ($disk->exists($p->object_key) && ! $disk->delete($p->object_key)) {
                        throw new \RuntimeException('DELETE_FAILED');
                    }
                } catch (\Throwable) {
                    $counts['storage_failures']++;

                    continue;
                }
                $counts['photos_deleted'] += DB::table('product_request_photos')
                    ->where('id', $p->id)
                    ->where('revoked', true)
                    ->delete();
            }
            $counts['drafts_deleted'] += DB::transaction(function () use ($id) {
                $r = DB::table('product_requests')
                    ->where('id', $id)
                    ->where('state', 'draft')
                    ->where('draft_expires_at', '<=', now())
                    ->lockForUpdate()
                    ->first();
                if (! $r || DB::table('product_request_photos')->where('request_id', $id)->exists()) {
                    return 0;
                }
                // A draft cannot have messages, candidates or notifications. Their restrictive FKs guard this invariant.
                DB::table('product_request_events')->where('request_id', $id)->delete();

                return DB::table('product_requests')->where('id', $id)->delete();
            }, 3);
        }

        return $counts;
    }
}
