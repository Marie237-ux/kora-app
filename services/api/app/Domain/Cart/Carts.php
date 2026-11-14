<?php

namespace App\Domain\Cart;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Domain\Shared\SessionIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Carts
{
    public const MAX_LINES = 200;

    public function guest(Request $http): ?object
    {
        $hash = app(SessionIdentity::class)->hash($http, false);

        return $hash
            ? DB::table('carts')
                ->where('guest_owner_hash', $hash)
                ->whereNull('user_id')
                ->whereNull('merged_into')
                ->first()
            : null;
    }

    public function current(Request $http, bool $lock = false): object
    {
        $key = $http->user()
            ? ['user_id' => $http->user()->id]
            : ['guest_owner_hash' => app(SessionIdentity::class)->hash($http)];
        DB::table('carts')->insertOrIgnore([
            ...$key,
            'id' => Records::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $q = DB::table('carts')->where($key)->whereNull('merged_into');
        if ($lock) {
            $q->lockForUpdate();
        }
        $cart = $q->first();
        abort_unless($cart, 404);

        return $cart;
    }

    public function version(object $cart, int $version): void
    {
        abort_unless((int) $cart->version === $version, 409);
    }

    public function bump(object $cart): object
    {
        DB::table('carts')
            ->where('id', $cart->id)
            ->update(['version' => $cart->version + 1, 'updated_at' => now()]);

        return Records::row('carts', $cart->id);
    }

    public function add(object $cart, string $offerId, int $quantity, bool $selected = true): void
    {
        $conditions = app(CartConditions::class)->current($offerId, true);
        if (! $conditions || $conditions['availability'] === 'unavailable') {
            Money::fail('offer_id', 'CART_OFFER_UNAVAILABLE');
        }
        $existing = DB::table('cart_lines')->where('cart_id', $cart->id)->where('offer_id', $offerId)->first();
        $qty = $quantity + ($existing?->quantity ?? 0);
        if ($qty > 1_000_000) {
            Money::fail('quantity', 'CART_QUANTITY_LIMIT');
        }
        if ($existing) {
            // Keep the first saved conditions and explicit selection for review; adding is not consent to price changes.
            DB::table('cart_lines')
                ->where('id', $existing->id)
                ->update(['quantity' => $qty, 'updated_at' => now()]);
        } else {
            if (DB::table('cart_lines')->where('cart_id', $cart->id)->count() >= self::MAX_LINES) {
                Money::fail('lines', 'CART_LINE_LIMIT');
            }
            DB::table('cart_lines')->insert([
                'id' => Records::id(),
                'cart_id' => $cart->id,
                'offer_id' => $offerId,
                'quantity' => $qty,
                'selected' => $selected,
                'saved_conditions' => Records::json($conditions),
                'conditions_saved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function edit(object $cart, string $id, array $data): void
    {
        $line = $this->line($cart, $id);
        $values = ['quantity' => $data['quantity'], 'selected' => $data['selected'], 'updated_at' => now()];
        if (! empty($data['offer_id']) && $data['offer_id'] !== $line->offer_id) {
            $current = app(CartConditions::class)->current($data['offer_id'], true);
            if (! $current || $current['availability'] === 'unavailable') {
                Money::fail('offer_id', 'CART_OFFER_UNAVAILABLE');
            }
            abort_if(
                DB::table('cart_lines')->where('cart_id', $cart->id)->where('offer_id', $data['offer_id'])->exists(),
                409,
            );
            $values += [
                'offer_id' => $data['offer_id'],
                'saved_conditions' => Records::json($current),
                'conditions_saved_at' => now(),
            ];
        }
        DB::table('cart_lines')->where('id', $line->id)->update($values);
    }

    public function line(object $cart, string $id): object
    {
        $line = DB::table('cart_lines')->where('cart_id', $cart->id)->where('id', $id)->first();
        abort_unless($line, 404);

        return $line;
    }

    public function accept(object $cart, array $accepted): void
    {
        $lines = [];
        foreach ($accepted as $choice) {
            $lines[$choice['id']] = $this->line($cart, $choice['id']);
        }
        // Sorted offer locks avoid lock inversion when accepting several changed lines.
        $offerIds = array_column($lines, 'offer_id');
        sort($offerIds);
        $current = [];
        foreach ($offerIds as $offerId) {
            $current[$offerId] = app(CartConditions::class)->current($offerId, true);
        }
        foreach ($accepted as $choice) {
            $conditions = $current[$lines[$choice['id']]->offer_id];
            if (
                ! $conditions ||
                ! hash_equals(app(CartConditions::class)->hash($conditions), $choice['conditions_hash'])
            ) {
                abort(409);
            }
            DB::table('cart_lines')
                ->where('id', $choice['id'])
                ->update([
                    'saved_conditions' => Records::json($conditions),
                    'conditions_saved_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    public function merge(Request $http, int $version): array
    {
        abort_unless($http->user(), 401);
        $target = $this->current($http);
        $guest = $this->guest($http);
        if (! $guest) {
            $this->version($target, $version);

            return ['cart' => $this->dto($target), 'merged' => false, 'conflicts' => []];
        }
        $locked = DB::table('carts')
            ->whereIn('id', [$target->id, $guest->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $target = $locked[$target->id];
        $guest = $locked[$guest->id];
        $this->version($target, $version);
        if ($guest->merged_into) {
            return ['cart' => $this->dto($target), 'merged' => false, 'conflicts' => []];
        }
        $conflicts = [];
        foreach (
            DB::table('cart_lines')->where('cart_id', $guest->id)->orderBy('created_at')->orderBy('id')->get() as $line
        ) {
            $existing = DB::table('cart_lines')
                ->where('cart_id', $target->id)
                ->where('offer_id', $line->offer_id)
                ->first();
            if ($existing) {
                $quantity = $existing->quantity + $line->quantity;
                if ($quantity > 1_000_000) {
                    Money::fail('quantity', 'CART_QUANTITY_LIMIT');
                }
                $conflicts[] = [
                    'offer_id' => $line->offer_id,
                    'kind' => 'quantity_combined',
                    'account_quantity' => (int) $existing->quantity,
                    'guest_quantity' => (int) $line->quantity,
                    'quantity' => $quantity,
                ];
                if (
                    app(CartConditions::class)->hash(json_decode($existing->saved_conditions, true)) !==
                    app(CartConditions::class)->hash(json_decode($line->saved_conditions, true))
                ) {
                    $conflicts[] = ['offer_id' => $line->offer_id, 'kind' => 'saved_conditions_differ'];
                }
                if ((bool) $existing->selected !== (bool) $line->selected) {
                    $conflicts[] = ['offer_id' => $line->offer_id, 'kind' => 'account_selection_preserved'];
                }
                DB::table('cart_lines')
                    ->where('id', $existing->id)
                    ->update(['quantity' => $quantity, 'updated_at' => now()]);
                DB::table('cart_lines')->where('id', $line->id)->delete();
            } else {
                if (DB::table('cart_lines')->where('cart_id', $target->id)->count() >= self::MAX_LINES) {
                    Money::fail('lines', 'CART_LINE_LIMIT');
                }
                $snapshot = json_decode($line->saved_conditions, true);
                foreach (
                    DB::table('cart_lines')
                        ->where('cart_id', $target->id)
                        ->get(['offer_id', 'saved_conditions']) as $other
                ) {
                    $otherSnapshot = json_decode($other->saved_conditions, true);
                    if (
                        $snapshot['product_id'] === $otherSnapshot['product_id'] &&
                        $snapshot['variant_id'] !== $otherSnapshot['variant_id']
                    ) {
                        $conflicts[] = ['offer_id' => $line->offer_id, 'kind' => 'distinct_variant_preserved'];
                        break;
                    }
                }
                DB::table('cart_lines')
                    ->where('id', $line->id)
                    ->update(['cart_id' => $target->id, 'updated_at' => now()]);
            }
        }
        DB::table('cart_shares')
            ->where('cart_id', $guest->id)
            ->update(['cart_id' => $target->id, 'updated_at' => now()]);
        foreach (DB::table('cart_share_imports')->where('cart_id', $guest->id)->get() as $import) {
            DB::table('cart_share_imports')->insertOrIgnore([
                'cart_id' => $target->id,
                'share_id' => $import->share_id,
                'created_at' => $import->created_at,
            ]);
        }
        DB::table('cart_share_imports')->where('cart_id', $guest->id)->delete();
        DB::table('carts')
            ->where('id', $guest->id)
            ->update([
                'guest_owner_hash' => null,
                'merged_into' => $target->id,
                'merged_at' => now(),
                'updated_at' => now(),
            ]);

        return ['cart' => $this->dto($this->bump($target)), 'merged' => true, 'conflicts' => $conflicts];
    }

    public function dto(object $cart): array
    {
        $service = app(CartConditions::class);
        $selectedTotal = 0;
        $totalKnown = true;
        $needsReview = false;
        $lines = [];
        foreach (
            DB::table('cart_lines')->where('cart_id', $cart->id)->orderBy('created_at')->orderBy('id')->get() as $line
        ) {
            $saved = json_decode($line->saved_conditions, true);
            $view = $service->view($line->offer_id);
            $current = $view['conditions'] ?? null;
            $changes = $service->differences($saved, $current);
            $issues = ! $current || $current['availability'] === 'unavailable' ? ['unavailable'] : [];
            if ($current && $line->quantity < $current['minimum_quantity']) {
                $issues[] = 'minimum_quantity';
            }
            $amount = $current ? Money::multiply($current['unit_price'], (int) $line->quantity) : null;
            if ($line->selected) {
                if ($issues || $amount === null || $amount > Money::MAX - $selectedTotal) {
                    $totalKnown = false;
                } elseif ($totalKnown) {
                    $selectedTotal += $amount;
                }
                if ($changes || $issues) {
                    $needsReview = true;
                }
            }
            $lines[] = [
                'id' => $line->id,
                'offer_id' => $line->offer_id,
                'quantity' => (int) $line->quantity,
                'selected' => (bool) $line->selected,
                'added_at' => Records::iso($line->created_at),
                'conditions_saved_at' => Records::iso($line->conditions_saved_at),
                'saved_conditions' => [...$saved, 'attributes' => (object) $saved['attributes']],
                'current_conditions' => $current
                    ? [...$current, 'attributes' => (object) $current['attributes']]
                    : null,
                'conditions_hash' => $current ? $service->hash($current) : null,
                'changes' => $changes,
                'issues' => $issues,
                'current_line_total' => $amount === null ? null : (string) $amount,
                'offer' => $view['offer'] ?? null,
            ];
        }

        return [
            'id' => $cart->id,
            'version' => (int) $cart->version,
            'currency' => 'XAF',
            'lines' => $lines,
            'selected_subtotal' => $totalKnown ? (string) $selectedTotal : null,
            'needs_review' => $needsReview,
            'delivery_quote' => null,
            'payable_total' => null,
            'checkout_ready' => false,
            'stock_reserved' => false,
        ];
    }
}
