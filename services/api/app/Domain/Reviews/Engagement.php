<?php

namespace App\Domain\Reviews;

use App\Domain\Cart\CartConditions;
use App\Domain\Notifications\Notifications;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Price and availability alerts, viewed history (switchable off) and its clearing (§24). Alerts notify once,
 * through the notification centre and the user's preferences, then stop.
 */
final class Engagement
{
    public function alert(User $user, array $data): array
    {
        $conditions = app(CartConditions::class)->current($data['offer_id']);
        if (! $conditions) {
            Reviews::fail('offer_id', 'OFFER_UNAVAILABLE');
        }
        if ($data['kind'] === 'back_in_stock' && $conditions['availability'] !== 'unavailable') {
            Reviews::fail('kind', 'ALREADY_AVAILABLE');
        }
        if (isset($data['target_price']) && $data['target_price'] >= $conditions['unit_price']) {
            Reviews::fail('target_price', 'TARGET_NOT_LOWER');
        }
        if (
            DB::table('product_alerts')
                ->where('user_id', $user->id)
                ->where('offer_id', $data['offer_id'])
                ->where('kind', $data['kind'])
                ->where('status', 'active')
                ->exists()
        ) {
            Reviews::fail('offer_id', 'ALERT_EXISTS');
        }
        $id = Records::id();
        DB::table('product_alerts')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'offer_id' => $data['offer_id'],
            'kind' => $data['kind'],
            'baseline_price' => $conditions['unit_price'],
            'target_price' => $data['target_price'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->alertDto(Records::row('product_alerts', $id, false));
    }

    public function cancel(User $user, string $id): array
    {
        $alert = Records::row('product_alerts', $id);
        abort_unless((int) $alert->user_id === $user->id, 404);
        if ($alert->status === 'active') {
            DB::table('product_alerts')
                ->where('id', $id)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        return $this->alertDto(Records::row('product_alerts', $id, false));
    }

    /** Scheduled: a lower price than when the alert was set (or than the target), or the offer available again. */
    public function check(int $limit): int
    {
        $triggered = 0;
        foreach (
            DB::table('product_alerts')->where('status', 'active')->orderBy('created_at')->limit($limit)->pluck('id') as $id
        ) {
            $triggered += (int) DB::transaction(function () use ($id) {
                $alert = Records::row('product_alerts', $id);
                if ($alert->status !== 'active') {
                    return false;
                }
                $conditions = app(CartConditions::class)->current($alert->offer_id);
                if (! $conditions) {
                    return false;
                }
                $hit =
                    $alert->kind === 'price_drop'
                        ? $conditions['unit_price'] <
                            ($alert->target_price !== null ? $alert->target_price + 1 : $alert->baseline_price)
                        : $conditions['availability'] !== 'unavailable';
                if (! $hit) {
                    return false;
                }
                $name = DB::table('products')->where('id', $conditions['product_id'])->value('name');
                $name = json_decode($name, true);
                $price = number_format($conditions['unit_price'], 0, ',', ' ');
                $texts =
                    $alert->kind === 'price_drop'
                        ? [
                            'fr' => ['Baisse de prix', ($name['fr'] ?? '').' est maintenant à '.$price.' FCFA.'],
                            'en' => [
                                'Price drop',
                                ($name['en'] ?? ($name['fr'] ?? '')).' is now '.$price.' XAF.',
                            ],
                        ]
                        : [
                            'fr' => ['De nouveau disponible', ($name['fr'] ?? '').' est de nouveau disponible.'],
                            'en' => ['Back in stock', ($name['en'] ?? ($name['fr'] ?? '')).' is available again.'],
                        ];
                app(Notifications::class)->notify(
                    (int) $alert->user_id,
                    'reminder',
                    $alert->kind,
                    'alert:'.$alert->id,
                    $texts,
                    '/catalogue/'.$conditions['product_id'],
                );
                DB::table('product_alerts')
                    ->where('id', $id)
                    ->update([
                        'status' => 'triggered',
                        'triggered_at' => now(),
                        'updated_at' => now(),
                    ]);

                return true;
            }, 3);
        }

        return $triggered;
    }

    public function historyEnabled(int $userId): bool
    {
        $saved = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('channel', 'in_app')
            ->where('category', 'history')
            ->value('enabled');

        return $saved === null ? true : (bool) $saved;
    }

    /** Recorded only for signed-in customers who keep their history on; one row per product, latest view. */
    public function viewed(User $user, string $productId): bool
    {
        if (! $this->historyEnabled($user->id) || ! DB::table('products')->where('id', $productId)->exists()) {
            return false;
        }
        DB::table('product_views')->updateOrInsert(
            ['user_id' => $user->id, 'product_id' => $productId],
            ['viewed_at' => now()],
        );

        return true;
    }

    public function setHistory(User $user, bool $enabled): void
    {
        DB::table('notification_preferences')->updateOrInsert(
            ['user_id' => $user->id, 'channel' => 'in_app', 'category' => 'history'],
            ['enabled' => $enabled, 'updated_at' => now()],
        );
        if (! $enabled) {
            DB::table('product_views')->where('user_id', $user->id)->delete();
        }
    }

    public function alertDto(object $a): array
    {
        return [
            'id' => $a->id,
            'offer_id' => $a->offer_id,
            'kind' => $a->kind,
            'baseline_price' => (string) $a->baseline_price,
            'target_price' => $a->target_price === null ? null : (string) $a->target_price,
            'status' => $a->status,
            'triggered_at' => Records::iso($a->triggered_at),
            'created_at' => Records::iso($a->created_at),
        ];
    }
}
