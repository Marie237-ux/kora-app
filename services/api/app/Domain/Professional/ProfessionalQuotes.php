<?php

namespace App\Domain\Professional;

use App\Domain\Cart\Carts;
use App\Domain\Delivery\DeliveryQuotes;
use App\Domain\Documents\BillingIdentity;
use App\Domain\Documents\CustomerDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Orders\Orders;
use App\Domain\Pricing\AgreementResolver;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Immutable issued revisions, explicit company consent and a single conversion through the regular checkout. */
final class ProfessionalQuotes
{
    public function owned(object $profile, string $id, bool $lock = false): object
    {
        $q = DB::table('professional_quotes')->where('id', $id)->where('profile_id', $profile->id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $quote = $q->first();
        abort_unless($quote, 404);

        return $quote;
    }

    public function request(object $profile, object $cart, object $address, string $notes, User $actor): array
    {
        $view = app(Carts::class)->dto($cart);
        $selected = collect($view['lines'])->where('selected', true);
        if ($view['needs_review'] || $selected->isEmpty()) {
            BusinessProfiles::fail('cart', 'CART_NEEDS_REVIEW');
        }
        $lines = [];
        foreach ($selected->sortBy('offer_id') as $line) {
            $terms = app(Inventory::class)->offerTerms($line['offer_id']);
            $lines[] = [
                'offer_id' => $line['offer_id'],
                'quantity' => $line['quantity'],
                'conditions' => $line['current_conditions'],
                'name' => json_decode($terms['product']->name, true),
                'sku' => $terms['variant']->sku,
                'shop' => ['id' => $terms['shop']->id, 'name' => $terms['shop']->name, 'slug' => $terms['shop']->slug],
            ];
        }
        $id = Records::id();
        DB::table('professional_quotes')->insert([
            'id' => $id,
            'profile_id' => $profile->id,
            'created_by' => $actor->id,
            'requested_lines' => Records::json($lines),
            'address_snapshot' => $address->address,
            'request_notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event($id, 'requested', $actor);

        return $this->dto(Records::row('professional_quotes', $id, false));
    }

    /** Proposal prices are entered by authorised staff; supplier costs still come from actual agreements. */
    public function issue(object $profile, object $quote, array $data, User $actor): array
    {
        abort_unless((int) $quote->version === $data['version'], 409);
        if (! in_array($quote->status, ['requested', 'issued', 'declined', 'expired'], true)) {
            BusinessProfiles::fail('quote', 'QUOTE_NOT_EDITABLE');
        }
        $requested = json_decode($quote->requested_lines, true);
        $prices = collect($data['lines'])->keyBy('offer_id');
        if ($prices->count() !== count($requested)) {
            BusinessProfiles::fail('lines', 'TERMS_CHANGED');
        }
        $buyer = User::findOrFail($quote->created_by);
        [$cart, $address] = app(ProfessionalCart::class)->create(
            $requested,
            json_decode($quote->address_snapshot, true),
            $buyer,
        );
        $cartView = app(Carts::class)->dto($cart);
        $lines = [];
        $internal = [];
        $items = [];
        foreach (collect($cartView['lines'])->sortBy('offer_id') as $line) {
            $input = $prices->get($line['offer_id']);
            if (! $input) {
                BusinessProfiles::fail('lines', 'TERMS_CHANGED');
            }
            $unit = Money::parse($input['unit_price']);
            if ($unit < 1 || $unit > $line['current_conditions']['unit_price']) {
                BusinessProfiles::fail('unit_price', 'PRICE_INVALID');
            }
            $terms = app(Inventory::class)->offerTerms($line['offer_id']);
            $agreement = app(AgreementResolver::class)->quote(
                $terms['shop']->supplier_id,
                $terms['product']->id,
                $terms['product']->category_id,
                (int) $terms['offer']->reference_price,
                $unit,
                $line['quantity'],
            );
            if (
                $agreement['negative_margin'] &&
                (! in_array('pricing.loss_override', $actor->permissions(), true) || empty($data['loss_reason']))
            ) {
                BusinessProfiles::fail('loss_reason', 'LOSS_APPROVAL_REQUIRED');
            }
            $items[] = Money::multiply($unit, $line['quantity']);
            $lines[] = [
                'offer_id' => $line['offer_id'],
                'quantity' => $line['quantity'],
                'conditions' => $line['current_conditions'],
                'name' => json_decode($terms['product']->name, true),
                'sku' => $terms['variant']->sku,
                'shop' => ['id' => $terms['shop']->id, 'name' => $terms['shop']->name, 'slug' => $terms['shop']->slug],
                'catalogue_unit_price' => (string) $line['current_conditions']['unit_price'],
                'unit_price' => (string) $unit,
                'line_total' => (string) Money::multiply($unit, $line['quantity']),
            ];
            $internal[] = ['offer_id' => $line['offer_id'], 'agreement' => $agreement];
        }
        $delivery = app(DeliveryQuotes::class)->create($cart, $address, $buyer, false);
        if ($delivery['status'] !== 'quoted' || $delivery['total'] === null) {
            BusinessProfiles::fail('delivery', 'DELIVERY_NOT_CONFIRMED');
        }
        $identity = app(BillingIdentity::class)->current();
        $demo = $identity['missing'] !== [] || str_starts_with($quote->number ?? '', 'DEMO-');
        $year = now('Africa/Douala')->year;
        $number =
            $quote->number ??
            app(DocumentNumbers::class)->next(
                ($demo ? 'demo-professional:' : 'professional:').$year,
                ($demo ? 'DEMO-DEV-' : 'DEV-').$year.'-',
                6,
            );
        $itemsTotal = Money::sum($items);
        $total = Money::sum([$itemsTotal, Money::parse($delivery['total'])]);
        $snapshot = [
            'number' => $number,
            'currency' => 'XAF',
            'demo' => $demo,
            'buyer' => json_decode($profile->billing_identity, true),
            'seller' => array_diff_key($identity, array_flip(['version', 'missing'])),
            'lines' => $lines,
            'items_total' => (string) $itemsTotal,
            'delivery' => [
                'total' => $delivery['total'],
                'breakdown' => $delivery['breakdown'],
                'demo_amounts' => $delivery['demo_amounts'],
            ],
            'totals' => app(CustomerDocuments::class)->totals(
                $total,
                $identity['tax_regime'] === 'vat' ? $identity['vat_rate_basis_points'] : null,
            ),
            'conditions' => $data['conditions'],
            'stock_reserved' => false,
            'supplier_confirmation_required' => collect($delivery['lines'])->contains(
                fn ($line) => $line['from_supplier'] > 0,
            ),
            'payment_terms' => 'prepaid_monetbil',
            'campaigns_combined' => false,
        ];
        $revisionNumber =
            (int) DB::table('professional_quote_revisions')->where('quote_id', $quote->id)->max('revision') + 1;
        $id = Records::id();
        DB::table('professional_quote_revisions')->insert([
            'id' => $id,
            'quote_id' => $quote->id,
            'revision' => $revisionNumber,
            'snapshot' => Records::json($snapshot),
            'snapshot_sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'internal_snapshot' => Records::json([
                'lines' => $internal,
                'loss_reason' => $data['loss_reason'] ?? null,
                'cart_id' => $cart->id,
                'delivery_quote_id' => $delivery['id'],
            ]),
            'valid_until' => CarbonImmutable::parse($data['valid_until'])->utc(),
            'issued_by' => $actor->id,
            'created_at' => now(),
        ]);
        DB::table('professional_quotes')
            ->where('id', $quote->id)
            ->update([
                'number' => $number,
                'current_revision_id' => $id,
                'status' => 'issued',
                'version' => $quote->version + 1,
                'decline_reason' => null,
                'updated_at' => now(),
            ]);
        $this->event($quote->id, 'issued', $actor, ['revision_id' => $id, 'revision' => $revisionNumber]);

        return $this->dto(
            Records::row('professional_quotes', $quote->id, false),
            in_array('pricing.read', $actor->permissions(), true),
        );
    }

    public function decide(object $quote, array $data, bool $accept, User $actor): array
    {
        abort_unless((int) $quote->version === $data['version'], 409);
        $revision = $this->current($quote);
        if ($quote->status !== 'issued' || ! CarbonImmutable::parse($revision->valid_until, 'UTC')->isFuture()) {
            BusinessProfiles::fail('quote', 'QUOTE_NOT_ACCEPTABLE');
        }
        if ($accept) {
            $snapshot = json_decode($revision->snapshot, true);
            if ($data['revision_id'] !== $revision->id || $data['accepted_total'] !== $snapshot['totals']['total']) {
                BusinessProfiles::fail('accepted_total', 'TOTAL_CHANGED');
            }
        }
        DB::table('professional_quotes')
            ->where('id', $quote->id)
            ->update([
                'status' => $accept ? 'accepted' : 'declined',
                'accepted_by' => $accept ? $actor->id : null,
                'accepted_at' => $accept ? now() : null,
                'decline_reason' => $accept ? null : $data['reason'] ?? null,
                'version' => $quote->version + 1,
                'updated_at' => now(),
            ]);
        $this->event($quote->id, $accept ? 'accepted' : 'declined', $actor, ['revision_id' => $revision->id]);

        return $this->dto(Records::row('professional_quotes', $quote->id, false));
    }

    public function convert(object $quote, array $data, User $actor): array
    {
        if ($quote->order_id) {
            return $this->orderView(Records::row('orders', $quote->order_id, false), $actor);
        }
        abort_unless((int) $quote->version === $data['version'], 409);
        $revision = $this->current($quote);
        $snapshot = json_decode($revision->snapshot, true);
        if ($quote->status !== 'accepted' || ! CarbonImmutable::parse($revision->valid_until, 'UTC')->isFuture()) {
            BusinessProfiles::fail('quote', 'QUOTE_NOT_CONVERTIBLE');
        }
        if ($data['accepted_total'] !== $snapshot['totals']['total']) {
            BusinessProfiles::fail('accepted_total', 'TOTAL_CHANGED');
        }
        // Normal order creation takes this sequence before its inventory locks. Keep that order here too.
        app(DocumentNumbers::class)->lock('order');
        [$cart, $address] = app(ProfessionalCart::class)->create(
            $snapshot['lines'],
            json_decode($quote->address_snapshot, true),
            $actor,
        );
        app(ProfessionalCart::class)->selected(
            collect(app(Carts::class)->dto($cart)['lines'])
                ->keyBy('id')
                ->all(),
            $snapshot['lines'],
        );
        $delivery = app(DeliveryQuotes::class)->create($cart, $address, $actor, false);
        if ($delivery['status'] !== 'quoted') {
            BusinessProfiles::fail('delivery', 'DELIVERY_NOT_CONFIRMED');
        }
        app(DeliveryQuotes::class)->holdSlot(
            Records::row('delivery_quotes', $delivery['id']),
            $cart,
            $data['rule_id'],
            $data['date'],
        );
        $order = app(Orders::class)->create(
            $cart,
            Records::row('delivery_quotes', $delivery['id']),
            $data['accepted_total'],
            $actor,
            $revision,
        );
        DB::table('professional_quotes')
            ->where('id', $quote->id)
            ->update([
                'order_id' => $order['id'],
                'status' => 'converted',
                'version' => $quote->version + 1,
                'updated_at' => now(),
            ]);
        $this->event($quote->id, 'converted', $actor, [
            'order_id' => $order['id'],
            'recalculated_delivery_total' => $delivery['total'],
            'agreed_delivery_total' => $snapshot['delivery']['total'],
        ]);

        return $order;
    }

    /** No stock or slot is reserved by this preview; only company buyers can request it. */
    public function preview(object $quote, User $actor): array
    {
        $revision = $this->current($quote);
        if (
            ! in_array($quote->status, ['issued', 'accepted'], true) ||
            ! CarbonImmutable::parse($revision->valid_until, 'UTC')->isFuture()
        ) {
            BusinessProfiles::fail('quote', 'QUOTE_NOT_CONVERTIBLE');
        }
        $snapshot = json_decode($revision->snapshot, true);
        [$cart, $address] = app(ProfessionalCart::class)->create(
            $snapshot['lines'],
            json_decode($quote->address_snapshot, true),
            $actor,
        );
        app(ProfessionalCart::class)->selected(
            collect(app(Carts::class)->dto($cart)['lines'])
                ->keyBy('id')
                ->all(),
            $snapshot['lines'],
        );
        $delivery = app(DeliveryQuotes::class)->create($cart, $address, $actor, false);
        if ($delivery['status'] !== 'quoted') {
            BusinessProfiles::fail('delivery', 'DELIVERY_NOT_CONFIRMED');
        }

        return [
            'delivery' => [
                ...$delivery,
                'total' => $snapshot['delivery']['total'],
                'payable_delivery' => $snapshot['delivery']['total'],
                'breakdown' => $snapshot['delivery']['breakdown'],
            ],
            'accepted_total' => $snapshot['totals']['total'],
            'stock_reserved' => false,
        ];
    }

    public function current(object $quote): object
    {
        if (! $quote->current_revision_id) {
            BusinessProfiles::fail('quote', 'QUOTE_NOT_ISSUED');
        }

        return Records::row('professional_quote_revisions', $quote->current_revision_id, false);
    }

    public function event(string $id, string $action, ?User $actor, array $data = []): void
    {
        DB::table('professional_quote_events')->insert([
            'quote_id' => $id,
            'action' => $action,
            'actor_id' => $actor?->id,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
        Records::audit($actor, 'professional.quote_'.$action, $id, $data);
    }

    public function orderView(object $order, User $actor): array
    {
        $view = app(Orders::class)->dto($order);
        if ((int) $order->user_id !== (int) $actor->id) {
            $view['payable'] = false;
            if ($view['payment']) {
                $view['payment']['payment_url'] = null;
            }
        }

        return $view;
    }

    public function expire(string $id): void
    {
        $initial = Records::row('professional_quotes', $id, false);
        Records::row('professional_profiles', $initial->profile_id);
        $quote = Records::row('professional_quotes', $id);
        if (
            ! in_array($quote->status, ['issued', 'accepted'], true) ||
            ! $quote->current_revision_id ||
            $quote->order_id
        ) {
            return;
        }
        if (CarbonImmutable::parse($this->current($quote)->valid_until, 'UTC')->isFuture()) {
            return;
        }
        DB::table('professional_quotes')
            ->where('id', $id)
            ->update(['status' => 'expired', 'version' => $quote->version + 1, 'updated_at' => now()]);
        $this->event($id, 'expired', null);
    }

    public function dto(object $quote, bool $internal = false): array
    {
        $revision = $quote->current_revision_id ? $this->current($quote) : null;
        $expired = $revision && ! CarbonImmutable::parse($revision->valid_until, 'UTC')->isFuture();
        $dto = [
            'id' => $quote->id,
            'profile_id' => $quote->profile_id,
            'number' => $quote->number,
            'version' => (int) $quote->version,
            'status' => $expired && in_array($quote->status, ['issued', 'accepted'], true) ? 'expired' : $quote->status,
            'current_revision_id' => $quote->current_revision_id,
            'requested_lines' => json_decode($quote->requested_lines, true),
            'request_notes' => $quote->request_notes,
            'accepted_at' => Records::iso($quote->accepted_at),
            'decline_reason' => $quote->decline_reason,
            'order_id' => $quote->order_id,
            'revisions' => DB::table('professional_quote_revisions')
                ->where('quote_id', $quote->id)
                ->orderBy('revision')
                ->get()
                ->map(
                    fn ($r) => [
                        'id' => $r->id,
                        'revision' => (int) $r->revision,
                        'snapshot' => json_decode($r->snapshot, true),
                        'snapshot_sha256' => $r->snapshot_sha256,
                        'valid_until' => Records::iso($r->valid_until),
                        'issued_at' => Records::iso($r->created_at),
                        ...$internal ? ['internal' => json_decode($r->internal_snapshot, true)] : [],
                    ],
                ),
            'created_at' => Records::iso($quote->created_at),
        ];

        return $dto;
    }
}
