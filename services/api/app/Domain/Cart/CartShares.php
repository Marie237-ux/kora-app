<?php

namespace App\Domain\Cart;

use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;

final class CartShares
{
    public function create(object $cart, array $ids, int $days): array
    {
        $lines = [];
        foreach ($ids as $id) {
            $line = app(Carts::class)->line($cart, $id);
            $lines[] = ['offer_id' => $line->offer_id, 'quantity' => (int) $line->quantity];
        }
        if (
            DB::table('cart_shares')
                ->where('cart_id', $cart->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count() >= 20
        ) {
            Money::fail('shares', 'CART_SHARE_LIMIT');
        }
        $id = Records::id();
        $token = bin2hex(random_bytes(32));
        $expires = now()->addDays($days);
        DB::table('cart_shares')->insert([
            'id' => $id,
            'cart_id' => $cart->id,
            'token_hash' => hash('sha256', $token),
            'lines' => Records::json($lines),
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'url' => '/api/v1/cart-shares/'.$token, 'expires_at' => $expires->toIso8601String()];
    }

    public function active(string $token, bool $lock = false): object
    {
        $q = DB::table('cart_shares')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
        if ($lock) {
            $q->lockForUpdate();
        }
        $r = $q->first();
        abort_unless($r, 404);

        return $r;
    }

    public function publicView(object $share): array
    {
        return [
            'currency' => 'XAF',
            'expires_at' => Records::iso($share->expires_at),
            'lines' => array_map(
                fn ($line) => [
                    'offer_id' => $line['offer_id'],
                    'quantity' => $line['quantity'],
                    'offer' => app(PublicCatalogue::class)->offer($line['offer_id'], app()->getLocale()),
                ],
                json_decode($share->lines, true),
            ),
            'stock_reserved' => false,
        ];
    }

    public function copy(object $cart, string $token): array
    {
        // Do not hold a share lock while waiting for the cart owner to revoke it.
        // The caller already holds its cart; lock the share to serialize revoke/copy.
        $share = $this->active($token, true);
        if (DB::table('cart_share_imports')->where('cart_id', $cart->id)->where('share_id', $share->id)->exists()) {
            return ['cart' => app(Carts::class)->dto($cart), 'copied' => false, 'skipped' => []];
        }
        $lines = json_decode($share->lines, true);
        usort($lines, fn ($a, $b) => strcmp($a['offer_id'], $b['offer_id']));
        $skipped = [];
        foreach ($lines as $line) {
            $current = app(CartConditions::class)->current($line['offer_id'], true);
            if (! $current || $current['availability'] === 'unavailable') {
                $skipped[] = ['offer_id' => $line['offer_id'], 'reason' => 'unavailable'];

                continue;
            }
            app(Carts::class)->add($cart, $line['offer_id'], $line['quantity']);
        }
        DB::table('cart_share_imports')->insert([
            'cart_id' => $cart->id,
            'share_id' => $share->id,
            'created_at' => now(),
        ]);

        return [
            'cart' => app(Carts::class)->dto(app(Carts::class)->bump($cart)),
            'copied' => true,
            'skipped' => $skipped,
        ];
    }
}
