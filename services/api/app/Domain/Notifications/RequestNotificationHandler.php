<?php

namespace App\Domain\Notifications;

use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Procurement\Records;
use App\Domain\ProductRequests\ProductRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RequestNotificationHandler
{
    public function handle(object $event): string
    {
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('INVALID_PAYLOAD');
        }

        return match ($event->type) {
            'ContentPublished' => $this->published($event, $payload),
            'ProductRequestOfferReady' => $this->ready($event, $payload),
            'ProductRequestMessageAdded' => $this->message($event, $payload),
            default => throw new \InvalidArgumentException('UNSUPPORTED_EVENT'),
        };
    }

    private function validate(array $data, array $rules): array
    {
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            throw new \InvalidArgumentException('INVALID_PAYLOAD');
        }

        return $v->validated();
    }

    private function published(object $event, array $payload): string
    {
        $p = $this->validate($payload, [
            'entity' => ['required', 'in:suppliers,shops,categories,products,offers'],
            'id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:publish,archive'],
        ]);
        if ($p['action'] !== 'publish' || $p['entity'] === 'suppliers') {
            return 'projected';
        }
        $offers = DB::table('offers as o')
            ->join('variants as v', 'v.id', '=', 'o.variant_id')
            ->join('products as pr', 'pr.id', '=', 'v.product_id')
            ->select('o.id');
        match ($p['entity']) {
            'offers' => $offers->where('o.id', $p['id']),
            'products' => $offers->where('pr.id', $p['id']),
            'shops' => $offers->where('o.shop_id', $p['id']),
            'categories' => $offers->whereIn(
                'pr.category_id',
                app(CategoryAttributes::class)->descendantIds($p['id']),
            ),
        };
        DB::table('product_requests')
            ->whereIn('linked_offer_id', $offers)
            ->whereIn('state', ['preparing', 'identified'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($event) {
                foreach ($rows as $r) {
                    Records::event('ProductRequestOfferReady', $event->id.':'.$r->id.':'.$r->linked_offer_id, [
                        'request_id' => $r->id,
                        'offer_id' => $r->linked_offer_id,
                        'proposal' => (bool) $r->is_alternative && $r->alternative_accepted_at === null,
                    ]);
                }
            });

        return 'projected';
    }

    private function ready(object $event, array $payload): string
    {
        $p = $this->validate($payload, [
            'request_id' => ['required', 'uuid'],
            'offer_id' => ['required', 'uuid'],
            'proposal' => ['required', 'boolean'],
        ]);
        $r = DB::table('product_requests')->where('id', $p['request_id'])->lockForUpdate()->first();
        if (
            ! $r ||
            ! $r->user_id ||
            $r->linked_offer_id !== $p['offer_id'] ||
            ! in_array($r->state, ['identified', 'preparing', 'published'], true)
        ) {
            return 'suppressed';
        }
        $pendingAlternative = (bool) $r->is_alternative && $r->alternative_accepted_at === null;
        if ($pendingAlternative !== (bool) $p['proposal']) {
            return 'suppressed';
        }
        $offer = app(PublicCatalogue::class)->query()->where('offers.id', $p['offer_id'])->lockForUpdate()->first();
        if (! $offer || $offer->availability === 'unavailable') {
            return 'suppressed';
        }
        $kind = $pendingAlternative ? 'product_request_proposal' : 'product_request_available';
        $inserted = DB::table('account_notifications')->insertOrIgnore([
            'id' => Records::id(),
            'user_id' => $r->user_id,
            'event_id' => $event->id,
            'kind' => $kind,
            'request_id' => $r->id,
            'offer_id' => $p['offer_id'],
            'created_at' => now(),
        ]);
        if (! $pendingAlternative && $r->state !== 'published') {
            $r = app(ProductRequests::class)->change($r, ['state' => 'published']);
            app(ProductRequests::class)->event($r, 'offer_published', null, 'customer', [
                'state' => 'published',
                'offer_id' => $p['offer_id'],
            ]);
            Records::audit(null, 'product_request.offer_published', $r->id, ['offer_id' => $p['offer_id']]);
        }

        return $inserted ? 'delivered' : 'suppressed';
    }

    private function message(object $event, array $payload): string
    {
        $p = $this->validate($payload, ['request_id' => ['required', 'uuid'], 'message_id' => ['required', 'uuid']]);
        $r = DB::table('product_requests')->where('id', $p['request_id'])->lockForUpdate()->first();
        if (! $r || ! $r->user_id || $r->state === 'withdrawn') {
            return 'suppressed';
        }
        $message = DB::table('product_request_messages')
            ->where('request_id', $r->id)
            ->where('id', $p['message_id'])
            ->where('visibility', 'customer')
            ->where('author_kind', 'staff')
            ->first();
        if (! $message) {
            return 'suppressed';
        }
        $inserted = DB::table('account_notifications')->insertOrIgnore([
            'id' => Records::id(),
            'user_id' => $r->user_id,
            'event_id' => $event->id,
            'kind' => 'product_request_message',
            'request_id' => $r->id,
            'offer_id' => null,
            'created_at' => now(),
        ]);

        return $inserted ? 'delivered' : 'suppressed';
    }
}
