<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Carts;
use App\Domain\Cart\CartShares;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\ReadSnapshot;
use App\Domain\Shared\SessionCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CartController extends Controller
{
    public function show(Request $http, Carts $carts)
    {
        $cart = $carts->current($http);

        return [
            'data' => app(ReadSnapshot::class)->run(function () use ($cart, $carts, $http) {
                $guest = $http->user() ? $carts->guest($http) : null;

                return [
                    ...$carts->dto(Records::row('carts', $cart->id, false)),
                    'guest_merge_available' => $guest && DB::table('cart_lines')->where('cart_id', $guest->id)->exists(),
                ];
            }),
        ];
    }

    private function execute(Request $http, string $name, array $data, callable $work, int $status = 200)
    {
        $result = $http->user()
            ? app(CommandBus::class)->execute($http->user(), $name, $http->header('Idempotency-Key'), $data, $work)
            : app(SessionCommands::class)->execute($http, $name, $data, $work);

        return response()->json(['data' => $result], $status);
    }

    public function add(Request $http, Carts $carts)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'offer_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'selected' => ['required', 'boolean'],
        ]);

        return $this->execute(
            $http,
            'cart.add',
            $d,
            function () use ($http, $carts, $d) {
                $cart = $carts->current($http, true);
                $carts->version($cart, $d['version']);
                $carts->add($cart, $d['offer_id'], $d['quantity'], $d['selected']);

                return $carts->dto($carts->bump($cart));
            },
            201,
        );
    }

    public function edit(Request $http, string $id, Carts $carts)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'offer_id' => ['nullable', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'selected' => ['required', 'boolean'],
        ]);

        return $this->execute($http, 'cart.edit:'.$id, $d, function () use ($http, $carts, $d, $id) {
            $cart = $carts->current($http, true);
            $carts->version($cart, $d['version']);
            $carts->edit($cart, $id, $d);

            return $carts->dto($carts->bump($cart));
        });
    }

    public function remove(Request $http, string $id, Carts $carts)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->execute($http, 'cart.remove:'.$id, $d, function () use ($http, $carts, $d, $id) {
            $cart = $carts->current($http, true);
            $carts->version($cart, $d['version']);
            $carts->line($cart, $id);
            DB::table('cart_lines')->where('id', $id)->delete();

            return $carts->dto($carts->bump($cart));
        });
    }

    public function accept(Request $http, Carts $carts)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:id,conditions_hash'],
            'lines.*.id' => ['required', 'uuid', 'distinct'],
            'lines.*.conditions_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        return $this->execute($http, 'cart.accept_changes', $d, function () use ($http, $carts, $d) {
            $cart = $carts->current($http, true);
            $carts->version($cart, $d['version']);
            $carts->accept($cart, $d['lines']);

            return $carts->dto($carts->bump($cart));
        });
    }

    public function merge(Request $http, Carts $carts)
    {
        abort_unless($http->user(), 401);
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->execute($http, 'cart.merge', $d, fn () => $carts->merge($http, $d['version']));
    }

    public function shares(Request $http, Carts $carts)
    {
        $cart = $carts->current($http);

        return [
            'data' => DB::table('cart_shares')
                ->where('cart_id', $cart->id)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(
                    fn ($r) => [
                        'id' => $r->id,
                        'expires_at' => Records::iso($r->expires_at),
                        'revoked_at' => Records::iso($r->revoked_at),
                        'created_at' => Records::iso($r->created_at),
                    ],
                ),
        ];
    }

    public function share(Request $http, Carts $carts, CartShares $shares)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'line_ids' => ['required', 'array', 'min:1', 'max:200'],
            'line_ids.*' => ['required', 'uuid', 'distinct'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        return $this->execute(
            $http,
            'cart.share',
            $d,
            function () use ($http, $carts, $shares, $d) {
                $cart = $carts->current($http, true);
                $carts->version($cart, $d['version']);

                return $shares->create($cart, $d['line_ids'], $d['expires_in_days']);
            },
            201,
        );
    }

    public function revoke(Request $http, string $id, Carts $carts)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->execute($http, 'cart.share_revoke:'.$id, $d, function () use ($http, $carts, $d, $id) {
            $cart = $carts->current($http, true);
            $carts->version($cart, $d['version']);
            $r = DB::table('cart_shares')->where('id', $id)->where('cart_id', $cart->id)->lockForUpdate()->first();
            abort_unless($r, 404);
            DB::table('cart_shares')
                ->where('id', $id)
                ->update(['revoked_at' => $r->revoked_at ?? now(), 'updated_at' => now()]);

            return ['id' => $id, 'revoked' => true];
        });
    }

    public function publicShare(string $token, CartShares $shares)
    {
        return ['data' => $shares->publicView($shares->active($token))];
    }

    public function copy(Request $http, string $token, Carts $carts, CartShares $shares)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->execute($http, 'cart.share_copy:'.hash('sha256', $token), $d, function () use (
            $http,
            $carts,
            $shares,
            $d,
            $token,
        ) {
            $cart = $carts->current($http, true);
            $carts->version($cart, $d['version']);

            return $shares->copy($cart, $token);
        });
    }
}
