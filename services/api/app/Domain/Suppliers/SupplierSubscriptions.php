<?php

namespace App\Domain\Suppliers;

use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Optional supplier offers (§19): a Standard subscription and paid visibility, as tools the company may use.
 * Prices are entered by the company; both are off until the owner turns them on; nothing is mandatory. A supplier
 * consents explicitly; each period is an identified payment request and starts only once its payment is
 * verified (no recurring debit). Sponsored placements are shown apart and labelled, never mixed into the
 * organic catalogue order.
 */
final class SupplierSubscriptions
{
    public const PLACEMENTS = ['search_top', 'category_top', 'home_selection'];

    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', 'supplier_offers')->first();
        $value = $row ? json_decode($row->value, true) : [];

        return [
            'subscriptions_enabled' => (bool) ($value['subscriptions_enabled'] ?? false),
            'visibility_enabled' => (bool) ($value['visibility_enabled'] ?? false),
            // Sponsored items shown at most per placement, so a page is never mostly paid content.
            'slots_per_placement' => (int) ($value['slots_per_placement'] ?? 3),
            'renewal_notice_days' => (int) ($value['renewal_notice_days'] ?? 7),
            'version' => $row ? (int) $row->version : 0,
        ];
    }

    public function saveSettings(array $data, User $actor): array
    {
        $row = DB::table('business_settings')->where('key', 'supplier_offers')->lockForUpdate()->first();
        abort_unless(($row ? (int) $row->version : 0) === $data['version'], 409);
        $value = Records::json(
            array_intersect_key(
                $data,
                array_flip([
                    'subscriptions_enabled',
                    'visibility_enabled',
                    'slots_per_placement',
                    'renewal_notice_days',
                ]),
            ),
        );
        $row
            ? DB::table('business_settings')
                ->where('key', 'supplier_offers')
                ->update([
                    'value' => $value,
                    'version' => $row->version + 1,
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                ])
            : DB::table('business_settings')->insert([
                'key' => 'supplier_offers',
                'value' => $value,
                'version' => 1,
                'updated_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_offers.settings', 'supplier_offers', $data);

        return $this->settings();
    }

    private function enabled(string $kind): bool
    {
        return $this->settings()[$kind === 'visibility' ? 'visibility_enabled' : 'subscriptions_enabled'];
    }

    public function savePlan(?string $id, array $data, User $actor): array
    {
        if (
            $data['kind'] === 'visibility' &&
            (! in_array($data['benefits']['placement'] ?? null, self::PLACEMENTS, true) ||
                ($data['benefits']['max_offers'] ?? 0) < 1)
        ) {
            SupplierPortal::fail('benefits', 'PLAN_BENEFITS');
        }
        $values = [
            'code' => $data['code'],
            'kind' => $data['kind'],
            'name' => Records::json($data['name']),
            'description' => Records::json($data['description']),
            'price' => (int) $data['price'],
            'period_days' => $data['period_days'],
            'benefits' => Records::json($data['benefits']),
            'updated_at' => now(),
        ];
        if ($id) {
            $plan = Records::row('supplier_plans', $id);
            abort_unless((int) $plan->version === $data['version'], 409);
            // An offered plan is not edited in place: retire it and create another, so accepted terms stay clear.
            if ($plan->status !== 'draft') {
                SupplierPortal::fail('plan', 'PLAN_STATE');
            }
            DB::table('supplier_plans')
                ->where('id', $id)
                ->update([...$values, 'version' => $plan->version + 1]);
        } else {
            $id = Records::id();
            DB::table('supplier_plans')->insert([
                ...$values,
                'id' => $id,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }
        Records::audit($actor, 'supplier_offers.plan_saved', $id, [
            'price' => (int) $data['price'],
            'period_days' => $data['period_days'],
        ]);

        return $this->planDto(Records::row('supplier_plans', $id, false));
    }

    public function planStatus(string $id, array $data, User $actor): array
    {
        $plan = Records::row('supplier_plans', $id);
        abort_unless((int) $plan->version === $data['version'], 409);
        $allowed = ['activate' => 'draft', 'retire' => 'active'];
        if ($plan->status !== $allowed[$data['action']]) {
            SupplierPortal::fail('plan', 'PLAN_STATE');
        }
        DB::table('supplier_plans')
            ->where('id', $id)
            ->update([
                'status' => $data['action'] === 'activate' ? 'active' : 'retired',
                'version' => $plan->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_offers.plan_'.$data['action'], $id);

        return $this->planDto(Records::row('supplier_plans', $id, false));
    }

    /** By the team (awaiting the supplier's consent) or by a supplier manager (consent given now). */
    public function create(string $supplierId, array $data, User $actor, ?array $consent): array
    {
        Records::row('suppliers', $supplierId, false);
        $plan = DB::table('supplier_plans')->where('id', $data['plan_id'])->where('status', 'active')->first();
        abort_unless($plan, 404);
        if (! $this->enabled($plan->kind)) {
            SupplierPortal::fail('plan_id', 'OFFERS_DISABLED');
        }
        $benefits = json_decode($plan->benefits, true);
        $offers = array_values(array_unique($data['offer_ids'] ?? []));
        if ($plan->kind === 'visibility') {
            $own = DB::table('offers')
                ->join('shops', 'shops.id', '=', 'offers.shop_id')
                ->where('shops.supplier_id', $supplierId)
                ->whereIn('offers.id', $offers)
                ->count();
            if (! $offers || $own !== count($offers) || count($offers) > $benefits['max_offers']) {
                SupplierPortal::fail('offer_ids', 'VISIBILITY_OFFERS');
            }
        } else {
            $offers = [];
        }
        $id = Records::id();
        DB::table('supplier_subscriptions')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('supplier_subscription', 'ABO-', 6),
            'supplier_id' => $supplierId,
            'plan_id' => $plan->id,
            'plan_snapshot' => Records::json([
                'code' => $plan->code,
                'kind' => $plan->kind,
                'name' => json_decode($plan->name, true),
                'price' => (int) $plan->price,
                'period_days' => (int) $plan->period_days,
                'benefits' => $benefits,
                'plan_version' => (int) $plan->version,
            ]),
            'offer_ids' => Records::json($offers),
            'status' => 'proposed',
            'renew' => (bool) ($data['renew'] ?? false),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'supplier_offers.subscription_created', $id, [
            'supplier_id' => $supplierId,
            'plan' => $plan->code,
        ]);
        if ($consent) {
            return $this->consent($id, $consent, $actor);
        }

        return $this->dto(Records::row('supplier_subscriptions', $id, false));
    }

    /**
     * Explicit consent to the plan as shown (price, period, benefits): given in the portal by a supplier manager,
     * or recorded by the team from a signed document. The first payment request is then issued; a free plan
     * starts at once.
     */
    public function consent(string $id, array $consent, User $actor): array
    {
        $s = Records::row('supplier_subscriptions', $id);
        if ($s->status !== 'proposed') {
            SupplierPortal::fail('subscription', 'SUBSCRIPTION_STATE');
        }
        if ($consent['method'] === 'signed_document') {
            app(PrivateDocuments::class)->requireClean($consent['document_id'], $s->supplier_id, [
                'supplier_contract',
                'supplier_portal',
            ]);
        }
        $snapshot = json_decode($s->plan_snapshot, true);
        DB::table('supplier_subscriptions')
            ->where('id', $id)
            ->update([
                'status' => 'awaiting_payment',
                'consent' => Records::json([
                    ...$consent,
                    'by' => $actor->id,
                    'at' => now()->toIso8601String(),
                    'terms_sha256' => hash('sha256', $s->plan_snapshot),
                ]),
                'version' => $s->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_offers.consented', $id, ['method' => $consent['method']]);
        if ($snapshot['price'] === 0) {
            $this->startPeriod($id, $snapshot['period_days']);
        } else {
            $this->request($id, $actor);
        }

        return $this->dto(Records::row('supplier_subscriptions', $id, false));
    }

    private function request(string $subscriptionId, ?User $actor): void
    {
        $s = Records::row('supplier_subscriptions', $subscriptionId);
        $snapshot = json_decode($s->plan_snapshot, true);
        $id = Records::id();
        DB::table('supplier_billing_requests')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('supplier_billing', 'DPF-', 6),
            'subscription_id' => $s->id,
            'supplier_id' => $s->supplier_id,
            'amount' => $snapshot['price'],
            'period_days' => $snapshot['period_days'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'supplier_offers.payment_requested', $id, [
            'subscription_id' => $s->id,
            'amount' => $snapshot['price'],
        ]);
    }

    /** A first period starts today; a renewal continues the day after the current end. */
    private function startPeriod(string $subscriptionId, int $days): void
    {
        $s = Records::row('supplier_subscriptions', $subscriptionId);
        $today = CarbonImmutable::now('Africa/Douala')->startOfDay();
        $start =
            $s->status === 'active' && $s->ends_on && CarbonImmutable::parse($s->ends_on)->greaterThanOrEqualTo($today)
                ? CarbonImmutable::parse($s->ends_on)->addDay()
                : $today;
        DB::table('supplier_subscriptions')
            ->where('id', $subscriptionId)
            ->update([
                'status' => 'active',
                'starts_on' => $s->status === 'active' ? $s->starts_on : $start->toDateString(),
                'ends_on' => $start->addDays($days - 1)->toDateString(),
                'version' => $s->version + 1,
                'updated_at' => now(),
            ]);
    }

    /** Verified by the team with the real reference and proof; only then does the period start or extend. */
    public function markPaid(string $id, array $data, User $actor): array
    {
        $request = Records::row('supplier_billing_requests', $id);
        if ($request->status !== 'issued') {
            SupplierPortal::fail('request', 'BILLING_STATE');
        }
        $subscription = Records::row('supplier_subscriptions', $request->subscription_id, false);
        if (! in_array($subscription->status, ['awaiting_payment', 'active'], true)) {
            SupplierPortal::fail('request', 'SUBSCRIPTION_STATE');
        }
        // Proof of this supplier's payment, scanned clean.
        $evidence = app(PrivateDocuments::class)->requireClean($data['evidence_id'], $request->supplier_id, [
            'payment_evidence',
        ]);
        DB::table('supplier_billing_requests')
            ->where('id', $id)
            ->update([
                'status' => 'paid',
                'channel' => $data['channel'],
                'reference' => $data['reference'],
                'evidence_id' => $evidence->id,
                'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc(),
                'recorded_by' => $actor->id,
                'version' => $request->version + 1,
                'updated_at' => now(),
            ]);
        $this->startPeriod($request->subscription_id, (int) $request->period_days);
        Records::audit($actor, 'supplier_offers.paid', $id, [
            'amount' => (int) $request->amount,
            'reference' => $data['reference'],
        ]);
        Records::event('SupplierServicePaid', $id, [
            'supplier_id' => $request->supplier_id,
            'amount' => (string) $request->amount,
        ]);

        return $this->billingDto(Records::row('supplier_billing_requests', $id, false));
    }

    public function cancelRequest(string $id, array $data, User $actor): array
    {
        $request = Records::row('supplier_billing_requests', $id);
        if ($request->status !== 'issued') {
            SupplierPortal::fail('request', 'BILLING_STATE');
        }
        DB::table('supplier_billing_requests')
            ->where('id', $id)
            ->update([
                'status' => 'cancelled',
                'cancel_reason' => $data['reason'],
                'version' => $request->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_offers.request_cancelled', $id, ['reason' => $data['reason']]);

        return $this->billingDto(Records::row('supplier_billing_requests', $id, false));
    }

    /**
     * Cancellation by the supplier or the team. A paid period runs to its end unless the team ends it at once
     * with a reason; open payment requests are withdrawn; nothing is renewed.
     */
    public function cancel(string $id, array $data, User $actor, bool $team): array
    {
        $s = Records::row('supplier_subscriptions', $id);
        if (in_array($s->status, ['expired', 'cancelled'], true)) {
            SupplierPortal::fail('subscription', 'SUBSCRIPTION_STATE');
        }
        foreach (
            DB::table('supplier_billing_requests')
                ->where('subscription_id', $id)
                ->where('status', 'issued')
                ->lockForUpdate()
                ->get() as $r
        ) {
            DB::table('supplier_billing_requests')
                ->where('id', $r->id)
                ->update([
                    'status' => 'cancelled',
                    'cancel_reason' => 'subscription_cancelled',
                    'version' => $r->version + 1,
                    'updated_at' => now(),
                ]);
        }
        $immediate = $s->status !== 'active' || ($team && ! empty($data['immediate']));
        DB::table('supplier_subscriptions')
            ->where('id', $id)
            ->update([
                'status' => $immediate ? 'cancelled' : 'active',
                'renew' => false,
                'cancel_reason' => $data['reason'],
                'cancelled_at' => now(),
                'version' => $s->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_offers.cancelled', $id, [
            'immediate' => $immediate,
            'reason' => $data['reason'],
        ]);

        return $this->dto(Records::row('supplier_subscriptions', $id, false));
    }

    public function setRenewal(string $id, bool $renew, User $actor): array
    {
        $s = Records::row('supplier_subscriptions', $id);
        if (! in_array($s->status, ['awaiting_payment', 'active'], true) || $s->cancelled_at) {
            SupplierPortal::fail('subscription', 'SUBSCRIPTION_STATE');
        }
        DB::table('supplier_subscriptions')
            ->where('id', $id)
            ->update(['renew' => $renew, 'version' => $s->version + 1, 'updated_at' => now()]);
        Records::audit($actor, 'supplier_offers.renewal', $id, ['renew' => $renew]);

        return $this->dto(Records::row('supplier_subscriptions', $id, false));
    }

    /**
     * Daily: a renewal the supplier asked for gets its payment request before the end; a period that ended
     * without a paid continuation expires (and a cancelled one closes). Nothing is debited.
     */
    public function daily(): array
    {
        $today = CarbonImmutable::now('Africa/Douala')->toDateString();
        $notice = CarbonImmutable::now('Africa/Douala')
            ->addDays($this->settings()['renewal_notice_days'])
            ->toDateString();
        $requested = 0;
        $closed = 0;
        foreach (
            DB::table('supplier_subscriptions')
                ->where('status', 'active')
                ->where('renew', true)
                ->whereNull('cancelled_at')
                ->where('ends_on', '<=', $notice)
                ->where('ends_on', '>=', $today)
                ->pluck('id') as $id
        ) {
            DB::transaction(function () use ($id, &$requested) {
                $s = Records::row('supplier_subscriptions', $id);
                if (
                    ! DB::table('supplier_billing_requests')
                        ->where('subscription_id', $id)
                        ->where('status', 'issued')
                        ->exists()
                ) {
                    $this->request($id, null);
                    $requested++;
                }
            }, 3);
        }
        foreach (
            DB::table('supplier_subscriptions')->where('status', 'active')->where('ends_on', '<', $today)->pluck('id') as $id
        ) {
            DB::transaction(function () use ($id, &$closed) {
                $s = Records::row('supplier_subscriptions', $id);
                DB::table('supplier_subscriptions')
                    ->where('id', $id)
                    ->update([
                        'status' => $s->cancelled_at ? 'cancelled' : 'expired',
                        'version' => $s->version + 1,
                        'updated_at' => now(),
                    ]);
                foreach (
                    DB::table('supplier_billing_requests')
                        ->where('subscription_id', $id)
                        ->where('status', 'issued')
                        ->get() as $r
                ) {
                    DB::table('supplier_billing_requests')
                        ->where('id', $r->id)
                        ->update([
                            'status' => 'cancelled',
                            'cancel_reason' => 'period_ended_unpaid',
                            'version' => $r->version + 1,
                            'updated_at' => now(),
                        ]);
                }
                $closed++;
            }, 3);
        }

        return ['payment_requests' => $requested, 'closed' => $closed];
    }

    /**
     * Public: paid placements in force, among offers public right now, labelled as sponsored with their
     * sponsor. Shown in their own block; the organic catalogue order is never changed by payment.
     */
    public function sponsored(string $placement, ?string $categorySlug, string $locale): array
    {
        if (! $this->enabled('visibility')) {
            return [];
        }
        $today = CarbonImmutable::now('Africa/Douala')->toDateString();
        $subscriptions = DB::table('supplier_subscriptions')
            ->where('status', 'active')
            ->where('starts_on', '<=', $today)
            ->where('ends_on', '>=', $today)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->filter(fn ($s) => (json_decode($s->plan_snapshot, true)['benefits']['placement'] ?? null) === $placement);
        $query = app(PublicCatalogue::class)->query();
        if ($categorySlug) {
            $category = DB::table('categories')->where('slug', $categorySlug)->where('published', true)->first();
            $category
                ? $query->whereIn('products.category_id', app(CategoryAttributes::class)->descendantIds($category->id))
                : $query->whereRaw('1 = 0');
        }
        $ids = $subscriptions->flatMap(fn ($s) => json_decode($s->offer_ids, true))->unique()->values()->all();
        $offers = $query->whereIn('offers.id', $ids)->get()->keyBy('id');
        $items = [];
        foreach ($subscriptions as $s) {
            foreach (json_decode($s->offer_ids, true) as $offerId) {
                if (
                    isset($offers[$offerId]) &&
                    ! isset($items[$offerId]) &&
                    count($items) < $this->settings()['slots_per_placement']
                ) {
                    $dto = app(PublicCatalogue::class)->dto($offers[$offerId], $locale);
                    $items[$offerId] = [
                        'offer' => $dto,
                        'sponsored' => true,
                        'sponsor' => $dto['shop']['name'],
                        'label' => $locale === 'fr' ? 'Sponsorisé' : 'Sponsored',
                    ];
                }
            }
        }

        return array_values($items);
    }

    public function planDto(object $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'kind' => $p->kind,
            'name' => json_decode($p->name, true),
            'description' => json_decode($p->description, true),
            'price' => (string) $p->price,
            'period_days' => (int) $p->period_days,
            'benefits' => json_decode($p->benefits, true),
            'status' => $p->status,
            'version' => (int) $p->version,
        ];
    }

    public function dto(object $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'supplier_id' => $s->supplier_id,
            'plan' => json_decode($s->plan_snapshot, true),
            'offer_ids' => json_decode($s->offer_ids, true),
            'status' => $s->status,
            'consent' => $s->consent
                ? array_intersect_key(json_decode($s->consent, true), array_flip(['method', 'at', 'terms_sha256']))
                : null,
            'renew' => (bool) $s->renew,
            'starts_on' => $s->starts_on,
            'ends_on' => $s->ends_on,
            'cancelled_at' => Records::iso($s->cancelled_at),
            'cancel_reason' => $s->cancel_reason,
            'version' => (int) $s->version,
            'payment_requests' => DB::table('supplier_billing_requests')
                ->where('subscription_id', $s->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => $this->billingDto($r))
                ->all(),
        ];
    }

    public function billingDto(object $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'subscription_id' => $r->subscription_id,
            'supplier_id' => $r->supplier_id,
            'amount' => (string) $r->amount,
            'period_days' => (int) $r->period_days,
            'status' => $r->status,
            'reference' => $r->reference,
            'paid_at' => Records::iso($r->paid_at),
            'cancel_reason' => $r->cancel_reason,
            'version' => (int) $r->version,
            'created_at' => Records::iso($r->created_at),
        ];
    }
}
