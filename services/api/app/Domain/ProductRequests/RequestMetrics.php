<?php

namespace App\Domain\ProductRequests;

use App\Domain\Shared\ReadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestMetrics
{
    public function report(?string $from, ?string $to): array
    {
        $last = $to
            ? CarbonImmutable::parse($to, 'Africa/Douala')
            : CarbonImmutable::now('Africa/Douala')->startOfDay();
        $first = $from ? CarbonImmutable::parse($from, 'Africa/Douala') : $last->subDays(29);
        if ($first->greaterThan($last) || $first->diffInDays($last) > 365) {
            throw ValidationException::withMessages(['from' => __('operations.REQUEST_METRICS_RANGE')]);
        }

        return app(ReadSnapshot::class)->run(function () use ($first, $last) {
            $q = DB::table('product_requests')
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '>=', $first->startOfDay()->utc())
                ->where('submitted_at', '<', $last->addDay()->startOfDay()->utc());
            $counts = (clone $q)
                ->selectRaw('state, COUNT(*) as amount')
                ->groupBy('state')
                ->pluck('amount', 'state')
                ->map(fn ($v) => (int) $v)
                ->all();
            $replyCount = 0;
            $sum = 0;
            $max = null;
            $published = 0;
            $rows = (clone $q)
                ->select('product_requests.id', 'submitted_at')
                ->selectSub(
                    DB::table('product_request_messages')
                        ->selectRaw('MIN(created_at)')
                        ->whereColumn('request_id', 'product_requests.id')
                        ->where('author_kind', 'staff')
                        ->where('visibility', 'customer'),
                    'first_reply_at',
                )
                ->selectSub(
                    DB::table('product_request_events')
                        ->selectRaw('MIN(created_at)')
                        ->whereColumn('request_id', 'product_requests.id')
                        ->where('action', 'offer_published'),
                    'first_published_at',
                );
            $rows->orderBy('product_requests.id')->chunkById(
                500,
                function ($chunk) use (&$replyCount, &$sum, &$max, &$published) {
                    foreach ($chunk as $r) {
                        if ($r->first_published_at) {
                            $published++;
                        }
                        if (! $r->first_reply_at) {
                            continue;
                        }
                        $seconds = (int) CarbonImmutable::parse($r->submitted_at, 'UTC')->diffInSeconds(
                            CarbonImmutable::parse($r->first_reply_at, 'UTC'),
                            false,
                        );
                        if ($seconds < 0) {
                            continue;
                        }
                        $replyCount++;
                        $sum += $seconds;
                        $max = max($max ?? 0, $seconds);
                    }
                },
                'product_requests.id',
                'id',
            );
            $total = array_sum($counts);
            $groups = (clone $q)->whereNotNull('group_id')->distinct()->count('group_id');

            return [
                'from' => $first->toDateString(),
                'to' => $last->toDateString(),
                'timezone' => 'Africa/Douala',
                'submitted_count' => $total,
                'current_states' => (object) $counts,
                'group_count' => $groups,
                'grouped_request_count' => (clone $q)->whereNotNull('group_id')->count(),
                'product_drafts_count' => (clone $q)->whereNotNull('draft_product_id')->count(),
                'linked_offer_count' => (clone $q)->whereNotNull('linked_offer_id')->count(),
                'published_request_count' => $published,
                'supplier_proposal_count' => DB::table('product_request_candidates')
                    ->whereIn('request_id', (clone $q)->select('id'))
                    ->count(),
                'first_staff_reply' => [
                    'answered_count' => $replyCount,
                    'unanswered_count' => $total - $replyCount,
                    'mean_seconds' => $replyCount ? intdiv($sum, $replyCount) : null,
                    'max_seconds' => $max,
                ],
                'attributed_order_count' => null,
                'attributed_purchase_amount' => null,
                'purchase_attribution_available' => false,
                'known_as_of' => now()->toIso8601String(),
            ];
        });
    }
}
