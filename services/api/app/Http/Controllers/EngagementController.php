<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Carts;
use App\Domain\Notifications\Notifications;
use App\Domain\Procurement\Records;
use App\Domain\Reviews\Engagement;
use App\Domain\Reviews\Reviews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EngagementController extends OperationalController
{
    public function productReviews(string $id, Reviews $reviews)
    {
        return ['data' => $reviews->forProduct($id)];
    }

    public function myReviews(Request $http, Reviews $reviews)
    {
        return $this->page(
            DB::table('product_reviews')->where('user_id', $http->user()->id)->orderByDesc('created_at'),
            $http,
            fn ($r) => $reviews->ownDto($r),
        );
    }

    public function writeReview(Request $http, Reviews $reviews)
    {
        $data = $http->validate([
            'order_line_id' => ['required', 'uuid'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command($http, 'review.write', $data, fn ($d) => $reviews->write($http->user(), $d), 201);
    }

    public function reviewPhoto(Request $http, string $id, Reviews $reviews)
    {
        $data = $http->validate(['file' => ['required', 'file', 'max:10240']]);

        return response()->json(['data' => $reviews->photo($http->user(), $id, $data['file'])], 201);
    }

    public function report(Request $http, string $id, Reviews $reviews)
    {
        $data = $http->validate([
            'reason' => ['required', 'in:'.implode(',', Reviews::REJECTIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'review.report:'.$id,
            $data,
            fn ($d) => $reviews->report($http->user(), $id, $d),
            201,
        );
    }

    public function moderationQueue(Request $http, Reviews $reviews)
    {
        $d = $http->validate(['status' => ['nullable', 'in:pending,published,rejected']]);
        $q = DB::table('product_reviews')->orderBy('created_at')->orderBy('id');
        if (isset($d['status'])) {
            $q->where('status', $d['status']);
        }

        return $this->page($q, $http, fn ($r) => $reviews->staffDto($r));
    }

    public function moderate(Request $http, string $id, Reviews $reviews)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:publish,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'in:'.implode(',', Reviews::REJECTIONS)],
            'approved_photos' => ['nullable', 'array', 'max:4'],
            'approved_photos.*' => ['uuid'],
        ]);

        return $this->command(
            $http,
            'review.moderate:'.$id,
            $data,
            fn ($d) => $reviews->moderate($id, $d, $http->user()),
        );
    }

    public function reply(Request $http, string $id, Reviews $reviews)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command($http, 'review.reply:'.$id, $data, fn ($d) => $reviews->reply($id, $d, $http->user()));
    }

    public function handleReport(Request $http, string $id, Reviews $reviews)
    {
        $data = $http->validate([
            'action' => ['required', 'in:dismiss,withdraw'],
            'reason' => ['required_if:action,withdraw', 'nullable', 'in:'.implode(',', Reviews::REJECTIONS)],
        ]);

        return $this->command(
            $http,
            'review.report_handle:'.$id,
            $data,
            fn ($d) => $reviews->handleReport($id, $d, $http->user()),
        );
    }

    public function alerts(Request $http, Engagement $engagement)
    {
        return $this->page(
            DB::table('product_alerts')->where('user_id', $http->user()->id)->orderByDesc('created_at'),
            $http,
            fn ($a) => $engagement->alertDto($a),
        );
    }

    public function createAlert(Request $http, Engagement $engagement)
    {
        $data = $http->validate([
            'offer_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:price_drop,back_in_stock'],
            'target_price' => ['nullable', 'integer', 'min:1', 'prohibited_if:kind,back_in_stock'],
        ]);

        return $this->command($http, 'alert.create', $data, fn ($d) => $engagement->alert($http->user(), $d), 201);
    }

    public function cancelAlert(Request $http, string $id, Engagement $engagement)
    {
        return $this->command($http, 'alert.cancel:'.$id, [], fn () => $engagement->cancel($http->user(), $id));
    }

    public function viewed(Request $http, Engagement $engagement)
    {
        $data = $http->validate(['product_id' => ['required', 'uuid']]);

        return ['data' => ['recorded' => $engagement->viewed($http->user(), $data['product_id'])]];
    }

    public function history(Request $http, Engagement $engagement)
    {
        $user = $http->user();

        return [
            'data' => DB::table('product_views')
                ->where('user_id', $user->id)
                ->orderByDesc('viewed_at')
                ->limit(50)
                ->get()
                ->map(fn ($v) => ['product_id' => $v->product_id, 'viewed_at' => Records::iso($v->viewed_at)])
                ->values(),
            'meta' => ['enabled' => $engagement->historyEnabled($user->id)],
        ];
    }

    public function historySettings(Request $http, Engagement $engagement)
    {
        $data = $http->validate(['enabled' => ['required', 'boolean']]);
        $engagement->setHistory($http->user(), $data['enabled']);

        return ['data' => ['enabled' => (bool) $data['enabled']]];
    }

    public function clearHistory(Request $http)
    {
        DB::table('product_views')->where('user_id', $http->user()->id)->delete();

        return ['data' => ['cleared' => true]];
    }

    /** Puts a delivered purchase back in the cart at today's conditions; price changes show in the cart. */
    public function buyAgain(Request $http, Carts $carts)
    {
        $data = $http->validate(['order_line_id' => ['required', 'uuid']]);
        $line = DB::table('order_lines AS l')
            ->join('orders AS o', 'o.id', '=', 'l.order_id')
            ->where('l.id', $data['order_line_id'])
            ->where('o.user_id', $http->user()->id)
            ->first(['l.offer_id', 'l.quantity']);
        abort_unless($line, 404);

        return DB::transaction(function () use ($http, $carts, $line) {
            $cart = $carts->current($http, true);
            $carts->add($cart, $line->offer_id, (int) $line->quantity);
            $carts->bump($cart);

            return ['data' => ['added' => true, 'offer_id' => $line->offer_id, 'quantity' => (int) $line->quantity]];
        }, 3);
    }

    public function inbox(Request $http, Notifications $notifications)
    {
        return $this->page(
            DB::table('user_notifications')
                ->where('user_id', $http->user()->id)
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $http,
            fn ($n) => $notifications->dto($n),
        );
    }

    public function read(Request $http, string $id)
    {
        $updated = DB::table('user_notifications')
            ->where('id', $id)
            ->where('user_id', $http->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        abort_unless(
            $updated ||
                DB::table('user_notifications')->where('id', $id)->where('user_id', $http->user()->id)->exists(),
            404,
        );

        return ['data' => ['read' => true]];
    }

    public function preferences(Request $http, Notifications $notifications)
    {
        return ['data' => $notifications->preferences($http->user()->id)];
    }

    public function setPreferences(Request $http, Notifications $notifications)
    {
        $data = $http->validate([
            'reminder' => ['sometimes', 'array:in_app,email,web_push,whatsapp'],
            'reminder.*' => ['boolean'],
            'marketing' => ['sometimes', 'array:in_app,email,web_push,whatsapp'],
            'marketing.*' => ['boolean'],
        ]);

        return ['data' => $notifications->setPreferences($http->user()->id, $data)];
    }

    /** One-click unsubscribe from a link, without signing in; transactional notices cannot be switched off. */
    public function unsubscribe(Request $http, Notifications $notifications)
    {
        $d = $http->validate([
            'user' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:in_app,email,web_push,whatsapp'],
            'category' => ['required', 'in:reminder,marketing'],
            'token' => ['required', 'string', 'size:40'],
        ]);
        abort_unless($notifications->unsubscribe($d['user'], $d['channel'], $d['category'], $d['token']), 404);

        return ['data' => ['unsubscribed' => true]];
    }

    public function pushSubscribe(Request $http)
    {
        $data = $http->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);
        $hash = hash('sha256', $data['endpoint']);
        DB::table('web_push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => $hash],
            [
                'id' => DB::table('web_push_subscriptions')->where('endpoint_hash', $hash)->value('id') ?? Records::id(),
                'user_id' => $http->user()->id,
                'endpoint' => $data['endpoint'],
                'keys' => Records::json($data['keys']),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json(
            ['data' => ['subscribed' => true, 'sending_configured' => (bool) config('kora.channels.web_push')]],
            201,
        );
    }
}
