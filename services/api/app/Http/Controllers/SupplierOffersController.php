<?php

namespace App\Http\Controllers;

use App\Domain\Suppliers\SupplierPortal;
use App\Domain\Suppliers\SupplierSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Optional supplier subscriptions and paid visibility: team management, supplier portal side, public block. */
final class SupplierOffersController extends OperationalController
{
    // Public.

    public function sponsored(Request $http, SupplierSubscriptions $offers)
    {
        $d = $http->validate([
            'placement' => ['required', 'in:'.implode(',', SupplierSubscriptions::PLACEMENTS)],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        return ['data' => $offers->sponsored($d['placement'], $d['category'] ?? null, app()->getLocale())];
    }

    // Team.

    public function settings(SupplierSubscriptions $offers)
    {
        return ['data' => $offers->settings()];
    }

    public function saveSettings(Request $http, SupplierSubscriptions $offers)
    {
        $data = $http->validate([
            'subscriptions_enabled' => ['required', 'boolean'],
            'visibility_enabled' => ['required', 'boolean'],
            'slots_per_placement' => ['required', 'integer', 'min:1', 'max:6'],
            'renewal_notice_days' => ['required', 'integer', 'min:1', 'max:30'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->command(
            $http,
            'supplier_offers.settings',
            $data,
            fn ($d) => $offers->saveSettings($d, $http->user()),
        );
    }

    public function plans(Request $http, SupplierSubscriptions $offers)
    {
        return $this->page(
            DB::table('supplier_plans')->orderBy('kind')->orderBy('code'),
            $http,
            fn ($p) => $offers->planDto($p),
        );
    }

    public function savePlan(Request $http, SupplierSubscriptions $offers, ?string $id = null)
    {
        $data = $http->validate([
            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_-]+$/',
                'unique:supplier_plans,code'.($id ? ','.$id : ''),
            ],
            'kind' => ['required', 'in:subscription,visibility'],
            'name' => ['required', 'array:fr,en'],
            'name.*' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'description' => ['required', 'array:fr,en'],
            'description.*' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/'],
            // Entered by the company; zero means a free plan. No price is proposed by the software.
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'period_days' => ['required', 'integer', 'min:7', 'max:366'],
            'benefits' => ['required', 'array'],
            'benefits.placement' => ['nullable', 'in:'.implode(',', SupplierSubscriptions::PLACEMENTS)],
            'benefits.max_offers' => ['nullable', 'integer', 'min:1', 'max:12'],
            'benefits.items' => ['nullable', 'array', 'max:20'],
            'benefits.items.*' => ['array:fr,en'],
            'version' => [$id ? 'required' : 'nullable', 'integer'],
        ]);

        return $this->command(
            $http,
            'supplier_offers.plan:'.($id ?? 'new'),
            $data,
            fn ($d) => $offers->savePlan($id, $d, $http->user()),
            $id ? 200 : 201,
        );
    }

    public function planStatus(Request $http, string $id, SupplierSubscriptions $offers)
    {
        $data = $http->validate(['action' => ['required', 'in:activate,retire'], 'version' => ['required', 'integer']]);

        return $this->command(
            $http,
            'supplier_offers.plan_status:'.$id,
            $data,
            fn ($d) => $offers->planStatus($id, $d, $http->user()),
        );
    }

    public function subscriptions(Request $http, SupplierSubscriptions $offers)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:proposed,awaiting_payment,active,expired,cancelled'],
            'supplier_id' => ['nullable', 'uuid'],
        ]);
        $q = DB::table('supplier_subscriptions')->orderByDesc('created_at')->orderBy('id');
        foreach (['status', 'supplier_id'] as $key) {
            if (isset($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }

        return $this->page($q, $http, fn ($s) => $offers->dto($s));
    }

    public function propose(Request $http, string $id, SupplierSubscriptions $offers)
    {
        $data = $this->subscriptionRules($http);

        return $this->command(
            $http,
            'supplier_offers.propose:'.$id,
            $data,
            fn ($d) => $offers->create($id, $d, $http->user(), null),
            201,
        );
    }

    /** Consent the supplier gave outside the portal, proved by a signed document. */
    public function recordConsent(Request $http, string $id, SupplierSubscriptions $offers)
    {
        $data = $http->validate([
            'document_id' => ['required', 'uuid'],
            'signer_name' => ['required', 'string', 'max:150'],
        ]);

        return $this->command(
            $http,
            'supplier_offers.consent:'.$id,
            $data,
            fn ($d) => $offers->consent($id, ['method' => 'signed_document', ...$d], $http->user()),
        );
    }

    public function teamCancel(Request $http, string $id, SupplierSubscriptions $offers)
    {
        $data = $http->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'immediate' => ['required', 'boolean'],
        ]);

        return $this->command(
            $http,
            'supplier_offers.cancel:'.$id,
            $data,
            fn ($d) => $offers->cancel($id, $d, $http->user(), true),
        );
    }

    public function requests(Request $http, SupplierSubscriptions $offers)
    {
        $d = $http->validate(['status' => ['nullable', 'in:issued,paid,cancelled']]);
        $q = DB::table('supplier_billing_requests')->orderBy('created_at')->orderBy('id');
        if (isset($d['status'])) {
            $q->where('status', $d['status']);
        }

        return $this->page($q, $http, fn ($r) => $offers->billingDto($r));
    }

    public function markPaid(Request $http, string $id, SupplierSubscriptions $offers)
    {
        $data = $http->validate([
            'channel' => ['required', 'in:mobile_money,bank_transfer,cash,monetbil'],
            'reference' => ['required', 'string', 'max:150'],
            'paid_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'evidence_id' => ['required', 'uuid'],
        ]);

        return $this->command(
            $http,
            'supplier_offers.paid:'.$id,
            $data,
            fn ($d) => $offers->markPaid($id, $d, $http->user()),
        );
    }

    public function cancelRequest(Request $http, string $id, SupplierSubscriptions $offers)
    {
        $data = $http->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->command(
            $http,
            'supplier_offers.cancel_request:'.$id,
            $data,
            fn ($d) => $offers->cancelRequest($id, $d, $http->user()),
        );
    }

    // Supplier portal (members of the supplier; manager for commitments).

    private function manager(Request $http, string $supplier): object
    {
        $member = app(SupplierPortal::class)->member($http->user(), $supplier);
        if ($member->role !== 'manager') {
            SupplierPortal::fail('role', 'MANAGER_REQUIRED');
        }

        return $member;
    }

    private function subscriptionRules(Request $http): array
    {
        return $http->validate([
            'plan_id' => ['required', 'uuid'],
            'offer_ids' => ['nullable', 'array', 'max:12'],
            'offer_ids.*' => ['uuid', 'distinct'],
            'renew' => ['nullable', 'boolean'],
        ]);
    }

    private function own(string $supplier, string $id): void
    {
        abort_unless(
            DB::table('supplier_subscriptions')->where('id', $id)->where('supplier_id', $supplier)->exists(),
            404,
        );
    }

    public function portalPlans(Request $http, string $supplier, SupplierSubscriptions $offers)
    {
        app(SupplierPortal::class)->member($http->user(), $supplier);
        $settings = $offers->settings();
        $kinds = array_keys(
            array_filter([
                'subscription' => $settings['subscriptions_enabled'],
                'visibility' => $settings['visibility_enabled'],
            ]),
        );

        return [
            'data' => DB::table('supplier_plans')
                ->where('status', 'active')
                ->whereIn('kind', $kinds)
                ->orderBy('kind')
                ->orderBy('code')
                ->get()
                ->map(fn ($p) => $offers->planDto($p))
                ->all(),
        ];
    }

    public function portalSubscriptions(Request $http, string $supplier, SupplierSubscriptions $offers)
    {
        app(SupplierPortal::class)->member($http->user(), $supplier);

        return $this->page(
            DB::table('supplier_subscriptions')
                ->where('supplier_id', $supplier)
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $http,
            fn ($s) => $offers->dto($s),
        );
    }

    public function subscribe(Request $http, string $supplier, SupplierSubscriptions $offers)
    {
        $this->manager($http, $supplier);
        $data = [...$this->subscriptionRules($http), ...$http->validate(['accept_terms' => ['required', 'accepted']])];

        return $this->command(
            $http,
            'supplier_offers.subscribe:'.$supplier,
            $data,
            fn ($d) => $offers->create($supplier, $d, $http->user(), ['method' => 'portal']),
            201,
        );
    }

    public function accept(Request $http, string $supplier, string $id, SupplierSubscriptions $offers)
    {
        $this->manager($http, $supplier);
        $this->own($supplier, $id);
        $http->validate(['accept_terms' => ['required', 'accepted']]);

        return $this->command(
            $http,
            'supplier_offers.accept:'.$id,
            [],
            fn () => $offers->consent($id, ['method' => 'portal'], $http->user()),
        );
    }

    public function portalCancel(Request $http, string $supplier, string $id, SupplierSubscriptions $offers)
    {
        $this->manager($http, $supplier);
        $this->own($supplier, $id);
        $data = $http->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->command(
            $http,
            'supplier_offers.portal_cancel:'.$id,
            $data,
            fn ($d) => $offers->cancel($id, $d, $http->user(), false),
        );
    }

    public function renewal(Request $http, string $supplier, string $id, SupplierSubscriptions $offers)
    {
        $this->manager($http, $supplier);
        $this->own($supplier, $id);
        $data = $http->validate(['renew' => ['required', 'boolean']]);

        return $this->command(
            $http,
            'supplier_offers.renewal:'.$id,
            $data,
            fn ($d) => $offers->setRenewal($id, $d['renew'], $http->user()),
        );
    }
}
