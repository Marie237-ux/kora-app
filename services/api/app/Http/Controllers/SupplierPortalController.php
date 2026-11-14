<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use App\Domain\Procurement\SupplierLedger;
use App\Domain\Shared\Money;
use App\Domain\Suppliers\SupplierPortal;
use App\Domain\Suppliers\SupplierWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Supplier side (members of one supplier) and team side (invitations, reviews, sharing). */
final class SupplierPortalController extends OperationalController
{
    private function member(Request $http, string $supplier): object
    {
        return app(SupplierPortal::class)->member($http->user(), $supplier);
    }

    // Supplier side.

    public function memberships(Request $http, SupplierPortal $portal)
    {
        return ['data' => $portal->memberships($http->user())];
    }

    public function accept(Request $http, string $token, SupplierPortal $portal)
    {
        return ['data' => DB::transaction(fn () => $portal->accept($token, $http->user()), 3)];
    }

    public function overview(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        return ['data' => $workspace->overview($supplier, $this->member($http, $supplier))];
    }

    public function offers(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return $this->page($workspace->offers($supplier), $http, fn ($o) => $workspace->offerDto($o));
    }

    public function declare(Request $http, string $supplier, string $offer, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $data = $http->validate([
            'quantity' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'valid_until' => ['required', 'date', 'after:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'preparation_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'note' => ['nullable', 'string', 'max:500', 'not_regex:/[<>]/'],
        ]);

        return $this->command(
            $http,
            "supplier.declare:$supplier:$offer",
            $data,
            fn ($d) => $workspace->declare($supplier, $offer, $d, $http->user()),
            201,
        );
    }

    public function purchases(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return $this->page($workspace->purchases($supplier), $http, fn ($p) => $workspace->purchaseDto($p));
    }

    public function respondPurchase(Request $http, string $supplier, string $id, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $data = $http->validate([
            'decision' => ['required', 'in:confirmed,declined'],
            'reference' => ['nullable', 'string', 'max:150', 'not_regex:/[<>]/'],
            'note' => ['nullable', 'string', 'max:1000', 'not_regex:/[<>]/', 'required_if:decision,declined'],
        ]);

        return $this->command(
            $http,
            "supplier.purchase_response:$id",
            $data,
            fn ($d) => $workspace->respondPurchase($supplier, $id, $d, $http->user()),
            201,
        );
    }

    public function collections(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return $this->page($workspace->collectionList($supplier), $http, fn ($c) => $workspace->collectionDto($c));
    }

    public function respondCollection(Request $http, string $supplier, string $id, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $data = $http->validate([
            'decision' => ['required', 'in:ready,issue'],
            'note' => ['nullable', 'string', 'max:1000', 'not_regex:/[<>]/', 'required_if:decision,issue'],
        ]);

        return $this->command(
            $http,
            "supplier.collection_response:$id",
            $data,
            fn ($d) => $workspace->respondCollection($supplier, $id, $d, $http->user()),
            201,
        );
    }

    public function warranties(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return ['data' => $workspace->warranties($supplier)];
    }

    public function statement(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $data = $http->validate([
            'as_of' => ['nullable', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);
        $at = ! empty($data['as_of']) ? CarbonImmutable::parse($data['as_of'])->utc() : CarbonImmutable::now('UTC');
        $ledger = app(SupplierLedger::class);

        return [
            ...$this->page($workspace->statement($supplier, $at), $http, fn ($r) => $ledger->dto($r)),
            'balances' => $workspace->balances($supplier, $at),
            'known_as_of' => $at->toIso8601String(),
        ];
    }

    public function contracts(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return ['data' => $workspace->contracts($supplier)];
    }

    public function documents(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return ['data' => $workspace->documents($supplier)];
    }

    public function download(Request $http, string $supplier, string $document, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return $workspace->download($supplier, $document, $http->user());
    }

    public function upload(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $data = $http->validate([
            'file' => ['required', 'file'],
            'title' => ['required', 'string', 'max:150', 'not_regex:/[<>]/'],
        ]);

        return response()->json(
            ['data' => $workspace->upload($supplier, $data['file'], $data['title'], $http->user())],
            201,
        );
    }

    public function proposals(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);

        return $this->page(
            DB::table('supplier_proposals')->where('supplier_id', $supplier)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($p) => $workspace->proposalDto($p),
        );
    }

    public function propose(Request $http, string $supplier, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $kind = $http->validate(['kind' => ['required', 'in:product,offer_update,profile']])['kind'];
        $translated = fn (string $key, int $max, bool $required) => [
            "payload.$key" => [$required ? 'required' : 'nullable', 'array:fr,en'],
            "payload.$key.fr" => [$required ? 'required' : 'nullable', 'string', "max:$max"],
            "payload.$key.en" => ['nullable', 'string', "max:$max"],
        ];
        $rules = match ($kind) {
            'product' => [
                'shop_id' => ['required', 'uuid'],
                'payload' => ['required', 'array:name,description,brand,sku,proposed_price,warranty,attributes,note'],
                ...$translated('name', 150, true),
                ...$translated('description', 10000, true),
                ...$translated('warranty', 1500, false),
                'payload.brand' => ['nullable', 'string', 'max:150'],
                'payload.sku' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.-]+$/'],
                'payload.proposed_price' => ['nullable', 'string', 'max:20'],
                'payload.attributes' => ['nullable', 'array', 'max:30'],
                'payload.attributes.*' => ['string', 'max:200'],
                'payload.note' => ['nullable', 'string', 'max:2000'],
            ],
            'offer_update' => [
                'offer_id' => ['required', 'uuid'],
                'payload' => ['required', 'array:proposed_price,minimum_quantity,warranty,note', 'min:1'],
                'payload.proposed_price' => ['nullable', 'string', 'max:20'],
                'payload.minimum_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                ...$translated('warranty', 1500, false),
                'payload.note' => ['nullable', 'string', 'max:2000'],
            ],
            'profile' => [
                'payload' => ['required', 'array:contact_name,phone,email,address,note', 'min:1'],
                'payload.contact_name' => ['nullable', 'string', 'max:150'],
                'payload.phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9][0-9 ().-]{5,38}$/'],
                'payload.email' => ['nullable', 'email', 'max:254'],
                'payload.address' => ['nullable', 'string', 'max:500'],
                'payload.note' => ['nullable', 'string', 'max:2000'],
            ],
        };
        $data = ['kind' => $kind, ...$http->validate($rules)];
        // Plain text only; a proposed price is a whole amount in XAF, kept as information for the team.
        array_walk_recursive($data['payload'], function ($value) {
            if (is_string($value) && preg_match('/[<>]/', $value)) {
                SupplierPortal::fail('payload', 'TEXT_MARKUP');
            }
        });
        if (isset($data['payload']['proposed_price'])) {
            $data['payload']['proposed_price'] = (string) Money::parse($data['payload']['proposed_price']);
        }

        return $this->command(
            $http,
            'supplier.propose:'.$supplier,
            $data,
            fn ($d) => $workspace->propose($supplier, $d, $http->user()),
            201,
        );
    }

    public function withdraw(Request $http, string $supplier, string $id, SupplierWorkspace $workspace)
    {
        $this->member($http, $supplier);
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'supplier.withdraw:'.$id,
            $data,
            fn ($d) => $workspace->withdraw($supplier, $id, $d, $http->user()),
        );
    }

    // Team side.

    public function settings(SupplierPortal $portal)
    {
        return ['data' => $portal->settings()];
    }

    public function saveSettings(Request $http, SupplierPortal $portal)
    {
        $data = $http->validate([
            'enabled' => ['required', 'boolean'],
            'availability' => ['required', 'in:review,direct'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->command(
            $http,
            'supplier_portal.settings',
            $data,
            fn ($d) => $portal->saveSettings($d, $http->user()),
        );
    }

    public function access(string $id, SupplierPortal $portal)
    {
        return ['data' => $portal->access($id)];
    }

    public function invite(Request $http, string $id, SupplierPortal $portal)
    {
        $data = $http->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'in:'.implode(',', SupplierPortal::ROLES)],
        ]);

        return $this->command(
            $http,
            'supplier_portal.invite:'.$id,
            $data,
            fn ($d) => $portal->invite($id, $d, $http->user()),
            201,
        );
    }

    public function revokeInvitation(Request $http, string $id, SupplierPortal $portal)
    {
        return $this->command(
            $http,
            'supplier_portal.revoke_invitation:'.$id,
            [],
            fn () => $portal->revokeInvitation($id, $http->user()),
        );
    }

    public function revokeMember(Request $http, string $id, SupplierPortal $portal)
    {
        return $this->command(
            $http,
            'supplier_portal.revoke_member:'.$id,
            [],
            fn () => $portal->revokeMember($id, $http->user()),
        );
    }

    public function staffProposals(Request $http, SupplierWorkspace $workspace)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:submitted,in_review,accepted,rejected,withdrawn'],
            'supplier_id' => ['nullable', 'uuid'],
        ]);
        $q = DB::table('supplier_proposals')->orderBy('created_at')->orderBy('id');
        foreach (['status', 'supplier_id'] as $key) {
            if (isset($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }

        return $this->page($q, $http, fn ($p) => $workspace->proposalDto($p));
    }

    public function review(Request $http, string $id, SupplierWorkspace $workspace)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:in_review,accept,reject'],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:decision,reject'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        return $this->command(
            $http,
            'supplier_portal.review:'.$id,
            $data,
            fn ($d) => $workspace->review($id, $d, $http->user()),
        );
    }

    public function declarations(Request $http, SupplierWorkspace $workspace)
    {
        $d = $http->validate(['status' => ['nullable', 'in:pending,applied,rejected']]);
        $q = DB::table('supplier_availability_declarations')->orderBy('created_at')->orderBy('id');
        if (isset($d['status'])) {
            $q->where('status', $d['status']);
        }

        return $this->page($q, $http, fn ($r) => $workspace->declarationDto($r));
    }

    public function reviewDeclaration(Request $http, string $id, SupplierWorkspace $workspace)
    {
        $data = $http->validate(['decision' => ['required', 'in:apply,reject']]);

        return $this->command(
            $http,
            'supplier_portal.declaration:'.$id,
            $data,
            fn ($d) => $workspace->reviewDeclaration($id, $d, $http->user()),
        );
    }

    public function sharePurchase(Request $http, string $id, SupplierWorkspace $workspace)
    {
        return $this->command(
            $http,
            'supplier_portal.share_purchase:'.$id,
            [],
            fn () => $workspace->sharePurchase($id, $http->user()),
        );
    }

    public function responses(Request $http, string $id)
    {
        Records::row('suppliers', $id, false);

        return $this->page(
            DB::table('supplier_responses')->where('supplier_id', $id)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($r) => [
                'id' => $r->id,
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
                'decision' => $r->decision,
                'reference' => $r->reference,
                'note' => $r->note,
                'responded_by' => (int) $r->responded_by,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function share(Request $http, string $id, SupplierWorkspace $workspace)
    {
        $data = $http->validate([
            'document_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:150', 'not_regex:/[<>]/'],
        ]);

        return $this->command(
            $http,
            'supplier_portal.share:'.$id,
            $data,
            fn ($d) => $workspace->share($id, $d, $http->user()),
        );
    }

    public function unshare(Request $http, string $id, string $share, SupplierWorkspace $workspace)
    {
        return $this->command(
            $http,
            'supplier_portal.unshare:'.$share,
            [],
            fn () => $workspace->unshare($id, $share, $http->user()),
        );
    }

    public function sharedDocuments(string $id, SupplierWorkspace $workspace)
    {
        Records::row('suppliers', $id, false);

        return ['data' => $workspace->documents($id)];
    }
}
