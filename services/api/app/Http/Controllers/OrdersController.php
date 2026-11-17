<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Carts;
use App\Domain\Delivery\DeliveryQuotes;
use App\Domain\Orders\Orders;
use App\Domain\Payments\Payments;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class OrdersController extends OperationalController
{
    public function index(Request $http, Orders $orders)
    {
        return $this->page(
            DB::table('orders')
                ->where('user_id', $http->user()->id)
                ->where(function ($query) use ($http) {
                    $query
                        ->whereNull('professional_quote_revision_id')
                        ->orWhereExists(function ($membership) use ($http) {
                            $membership
                                ->selectRaw('1')
                                ->from('professional_quote_revisions AS r')
                                ->join('professional_quotes AS q', 'q.id', '=', 'r.quote_id')
                                ->join('professional_members AS m', 'm.profile_id', '=', 'q.profile_id')
                                ->whereColumn('r.id', 'orders.professional_quote_revision_id')
                                ->where('m.user_id', $http->user()->id);
                        });
                })
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $http,
            fn ($order) => $orders->dto($order),
        );
    }

    public function show(Request $http, string $id, Orders $orders)
    {
        return ['data' => $orders->dto($orders->owned($id, $http->user()))];
    }

    public function create(Request $http, Carts $carts, DeliveryQuotes $quotes, Orders $orders)
    {
        $d = $http->validate([
            'quote_id' => ['required', 'uuid'],
            'quote_version' => ['required', 'integer', 'min:1'],
            'accepted_total' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
            'loyalty_quote_id' => ['nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'order.create',
            $d,
            function ($data) use ($http, $carts, $quotes, $orders) {
                // Lock order: cart, then quote — the same order as quote creation and slot holds.
                $cart = $carts->current($http, true);
                $quote = $quotes->owned($data['quote_id'], $http->user(), true);
                abort_unless((int) $quote->version === $data['quote_version'], 409);

                return $orders->create(
                    $cart,
                    $quote,
                    $data['accepted_total'],
                    $http->user(),
                    null,
                    $data['loyalty_quote_id'] ?? null,
                );
            },
            201,
        );
    }

    /** Not wrapped in the command bus: the provider is called outside any database transaction. */
    /** Change an unpaid order: it is cancelled and its items go back to the cart (see Orders::reopen). */
    public function reopen(Request $http, string $id, Orders $orders, Carts $carts)
    {
        return [
            'data' => DB::transaction(function () use ($http, $id, $orders, $carts) {
                $order = DB::table('orders')->where('id', $orders->owned($id, $http->user())->id)->lockForUpdate()->first();

                return $orders->reopen($order, $carts->current($http, true));
            }, 3),
        ];
    }

    public function pay(Request $http, string $id, Payments $payments)
    {
        $key = $http->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $returnUrl = rtrim((string) config('kora.storefront_url'), '/').'/'.app()->getLocale().'/orders/'.$id;

        return response()->json(['data' => $payments->start($id, $key, $http->user(), $returnUrl)], 201);
    }

    /** Browser return from the payment page: the transaction id is only a hint, verified with the provider. */
    public function check(Request $http, string $id, string $attemptId, Orders $orders, Payments $payments)
    {
        $d = $http->validate(['transaction_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.-]+$/']]);
        $orders->owned($id, $http->user());
        $attempt = DB::table('payment_attempts')->where('id', $attemptId)->where('order_id', $id)->first();
        abort_unless($attempt, 404);
        $payments->claim($attempt, $d['transaction_id']);
        $payments->verify($attempt->id, $d['transaction_id']);

        return ['data' => $orders->dto(Records::row('orders', $id, false))];
    }
}
