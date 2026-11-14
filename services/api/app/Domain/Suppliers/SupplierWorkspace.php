<?php

namespace App\Domain\Suppliers;

use App\Domain\Catalogue\CatalogueManager;
use App\Domain\Delivery\Couriers;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Payables;
use App\Domain\Procurement\Records;
use App\Domain\Procurement\SupplierLedger;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a supplier member sees and does, always limited to its own supplier: its shops and offers, availability
 * it declares, purchases the team chose to share, collections at its shops, warranty cases attributed to it,
 * its statement, shared documents and its proposals. Never other suppliers' agreements, KORA's selling prices
 * or margins, nor customers' identities, addresses or orders. Its answers inform the team; they never confirm
 * a purchase, a debt or a receipt by themselves.
 */
final class SupplierWorkspace
{
    /** Statement lines a supplier can see; internal revisions of KORA's records are left out. */
    private const LEDGER_KINDS = [
        'purchase_confirmed',
        'goods_received',
        'obligation_recognized',
        'payment_verified',
        'credit_approved',
        'goods_returned',
        'schedule_renegotiated',
    ];

    public function overview(string $supplierId, object $member): array
    {
        $supplier = Records::row('suppliers', $supplierId, false);

        return [
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name],
            'role' => $member->role,
            'shops' => DB::table('shops')
                ->where('supplier_id', $supplierId)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status'])
                ->map(fn ($s) => (array) $s)
                ->all(),
            'counts' => [
                'open_proposals' => DB::table('supplier_proposals')
                    ->where('supplier_id', $supplierId)
                    ->whereIn('status', ['submitted', 'in_review'])
                    ->count(),
                'purchases_awaiting_answer' => DB::table('purchase_orders AS p')
                    ->where('p.supplier_id', $supplierId)
                    ->whereNotNull('p.shared_with_supplier_at')
                    ->where('p.status', 'draft')
                    ->whereNotExists(
                        fn ($q) => $q
                            ->selectRaw('1')
                            ->from('supplier_responses AS r')
                            ->whereColumn('r.subject_id', 'p.id')
                            ->where('r.subject_type', 'purchase_order'),
                    )
                    ->count(),
                'collections_open' => $this->collections($supplierId)
                    ->whereNotIn('c.status', ['dropped', 'received', 'cancelled'])
                    ->count(),
            ],
        ];
    }

    public function offers(string $supplierId)
    {
        return DB::table('offers')
            ->join('shops', 'shops.id', '=', 'offers.shop_id')
            ->join('variants', 'variants.id', '=', 'offers.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->where('shops.supplier_id', $supplierId)
            ->orderBy('shops.name')
            ->orderBy('products.slug')
            ->orderBy('offers.id')
            ->select([
                'offers.id',
                'offers.status',
                'offers.availability',
                'offers.reference_price',
                'offers.minimum_quantity',
                'offers.warranty',
                'shops.name AS shop_name',
                'products.name AS product_name',
                'variants.sku',
            ]);
    }

    public function offerDto(object $o): array
    {
        $availability = DB::table('supplier_availability')->where('offer_id', $o->id)->first();
        $pending = DB::table('supplier_availability_declarations')
            ->where('offer_id', $o->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        return [
            'id' => $o->id,
            'shop' => $o->shop_name,
            'product' => json_decode($o->product_name, true),
            'sku' => $o->sku,
            'status' => $o->status,
            'availability' => $o->availability,
            // The supplier's own price agreed with KORA; KORA's selling price and margin are not shown here.
            'reference_price' => (string) $o->reference_price,
            'minimum_quantity' => (int) $o->minimum_quantity,
            'warranty' => json_decode($o->warranty, true),
            'declared' => $availability
                ? [
                    'quantity' => $availability->quantity === null ? null : (int) $availability->quantity,
                    'valid_until' => Records::iso($availability->valid_until),
                    'preparation_minutes' => (int) $availability->preparation_minutes,
                    'verified_at' => Records::iso($availability->verified_at),
                ]
                : null,
            'pending_declaration_id' => $pending?->id,
        ];
    }

    private function ownOffer(string $supplierId, string $offerId): object
    {
        $offer = DB::table('offers')
            ->join('shops', 'shops.id', '=', 'offers.shop_id')
            ->where('offers.id', $offerId)
            ->where('shops.supplier_id', $supplierId)
            ->first(['offers.*']);
        abort_unless($offer, 404);

        return $offer;
    }

    public function declare(string $supplierId, string $offerId, array $data, User $user): array
    {
        $this->ownOffer($supplierId, $offerId);
        $id = Records::id();
        DB::table('supplier_availability_declarations')->insert([
            'id' => $id,
            'supplier_id' => $supplierId,
            'offer_id' => $offerId,
            'quantity' => $data['quantity'] ?? null,
            'valid_until' => CarbonImmutable::parse($data['valid_until'])->utc(),
            'preparation_minutes' => $data['preparation_minutes'],
            'note' => $data['note'] ?? null,
            'declared_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($user, 'supplier_portal.availability_declared', $id, [
            'offer_id' => $offerId,
            'quantity' => $data['quantity'] ?? null,
        ]);
        if (app(SupplierPortal::class)->settings()['availability'] === 'direct') {
            $this->applyDeclaration($id, $user);
        }

        return $this->declarationDto(Records::row('supplier_availability_declarations', $id, false));
    }

    public function reviewDeclaration(string $id, array $data, User $actor): array
    {
        $declaration = Records::row('supplier_availability_declarations', $id);
        if ($declaration->status !== 'pending') {
            SupplierPortal::fail('declaration', 'DECLARATION_STATE');
        }
        if ($data['decision'] === 'apply') {
            $this->applyDeclaration($id, $actor);
        } else {
            DB::table('supplier_availability_declarations')
                ->where('id', $id)
                ->update([
                    'status' => 'rejected',
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
            Records::audit($actor, 'supplier_portal.availability_rejected', $id);
        }

        return $this->declarationDto(Records::row('supplier_availability_declarations', $id, false));
    }

    /** Same record the team fills after a phone call, with the portal declaration as its reference. */
    private function applyDeclaration(string $id, User $actor): void
    {
        $d = Records::row('supplier_availability_declarations', $id);
        if (CarbonImmutable::parse($d->valid_until, 'UTC')->isPast()) {
            SupplierPortal::fail('valid_until', 'DECLARATION_EXPIRED');
        }
        $current = DB::table('supplier_availability')->where('offer_id', $d->offer_id)->lockForUpdate()->first();
        $declarer = DB::table('users')->where('id', $d->declared_by)->value('name');
        DB::table('supplier_availability')->updateOrInsert(
            ['offer_id' => $d->offer_id],
            [
                'id' => $current?->id ?? Records::id(),
                'quantity' => $d->quantity,
                'verified_at' => $d->created_at,
                'valid_until' => $d->valid_until,
                'preparation_minutes' => $d->preparation_minutes,
                'contact' => Str::limit('Portail : '.$declarer, 150, ''),
                'reference' => 'PORTAL-'.$d->id,
                'verified_by' => $actor->id,
                'version' => $current ? $current->version + 1 : 1,
                'created_at' => $current?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        DB::table('supplier_availability_declarations')
            ->where('id', $id)
            ->update([
                'status' => 'applied',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_availability.verified', $d->offer_id, [
            'quantity' => $d->quantity,
            'source' => 'portal',
            'declaration_id' => $id,
        ]);
    }

    public function declarationDto(object $d): array
    {
        return [
            'id' => $d->id,
            'supplier_id' => $d->supplier_id,
            'offer_id' => $d->offer_id,
            'quantity' => $d->quantity === null ? null : (int) $d->quantity,
            'valid_until' => Records::iso($d->valid_until),
            'preparation_minutes' => (int) $d->preparation_minutes,
            'note' => $d->note,
            'status' => $d->status,
            'declared_by' => (int) $d->declared_by,
            'reviewed_at' => Records::iso($d->reviewed_at),
            'created_at' => Records::iso($d->created_at),
        ];
    }

    public function purchases(string $supplierId)
    {
        return DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->whereNotNull('shared_with_supplier_at')
            ->orderByDesc('shared_with_supplier_at')
            ->orderBy('id');
    }

    /** Quantities and the supplier's own unit cost; no contract internals, commercial snapshot or customer link. */
    public function purchaseDto(object $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'status' => $p->status,
            'mode' => $p->mode,
            'expected_total' => (string) $p->expected_total,
            'confirmation_reference' => $p->supplier_confirmation_reference,
            'confirmed_at' => Records::iso($p->confirmed_at),
            'shared_at' => Records::iso($p->shared_with_supplier_at),
            'lines' => DB::table('purchase_lines AS l')
                ->join('variants AS v', 'v.id', '=', 'l.variant_id')
                ->join('products AS pr', 'pr.id', '=', 'v.product_id')
                ->where('l.purchase_order_id', $p->id)
                ->orderBy('l.id')
                ->get(['l.id', 'l.quantity', 'l.received_quantity', 'l.unit_cost', 'v.sku', 'pr.name'])
                ->map(
                    fn ($l) => [
                        'id' => $l->id,
                        'product' => json_decode($l->name, true),
                        'sku' => $l->sku,
                        'quantity' => (int) $l->quantity,
                        'received_quantity' => (int) $l->received_quantity,
                        'unit_cost' => (string) $l->unit_cost,
                    ],
                )
                ->all(),
            'responses' => $this->responses('purchase_order', $p->id),
        ];
    }

    public function sharePurchase(string $id, User $actor): array
    {
        $order = Records::row('purchase_orders', $id);
        if (! $order->shared_with_supplier_at) {
            DB::table('purchase_orders')
                ->where('id', $id)
                ->update(['shared_with_supplier_at' => now()]);
            Records::audit($actor, 'supplier_portal.purchase_shared', $id, ['supplier_id' => $order->supplier_id]);
        }

        return $this->purchaseDto(Records::row('purchase_orders', $id, false));
    }

    public function respondPurchase(string $supplierId, string $id, array $data, User $user): array
    {
        $order = $this->purchases($supplierId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($order, 404);
        if (! in_array($order->status, ['draft', 'confirmed'], true)) {
            SupplierPortal::fail('purchase', 'RESPONSE_CLOSED');
        }
        if ($data['decision'] === 'confirmed' && blank($data['reference'] ?? null)) {
            SupplierPortal::fail('reference', 'REFERENCE_REQUIRED');
        }
        $this->respond($supplierId, 'purchase_order', $id, $data, $user);

        return $this->purchaseDto($order);
    }

    private function collections(string $supplierId)
    {
        return DB::table('supplier_collections AS c')
            ->join('shops AS s', 's.id', '=', 'c.shop_id')
            ->where('s.supplier_id', $supplierId);
    }

    public function collectionList(string $supplierId)
    {
        return $this->collections($supplierId)->orderByDesc('c.created_at')->orderBy('c.id')->select('c.*');
    }

    /** Where and what to hand over, and who collects; never the customer or the order behind a direct delivery. */
    public function collectionDto(object $c): array
    {
        $point = DB::table('collection_points')
            ->where('id', $c->point_id)
            ->first(['name', 'address']);

        return [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status,
            'direct' => $c->destination === 'customer',
            'point' => $point ? ['name' => $point->name, 'address' => $point->address] : null,
            'courier' => app(Couriers::class)->publicIdentity($c->courier_id),
            'packages' => $c->packages === null ? null : (int) $c->packages,
            'collected_at' => Records::iso($c->collected_at),
            'created_at' => Records::iso($c->created_at),
            'lines' => DB::table('supplier_collection_lines AS l')
                ->join('purchase_lines AS pl', 'pl.id', '=', 'l.purchase_line_id')
                ->join('variants AS v', 'v.id', '=', 'pl.variant_id')
                ->join('products AS pr', 'pr.id', '=', 'v.product_id')
                ->where('l.collection_id', $c->id)
                ->orderBy('l.id')
                ->get(['l.expected_quantity', 'l.collected_quantity', 'v.sku', 'pr.name'])
                ->map(
                    fn ($l) => [
                        'product' => json_decode($l->name, true),
                        'sku' => $l->sku,
                        'expected_quantity' => (int) $l->expected_quantity,
                        'collected_quantity' => $l->collected_quantity === null ? null : (int) $l->collected_quantity,
                    ],
                )
                ->all(),
            'responses' => $this->responses('collection', $c->id),
        ];
    }

    public function respondCollection(string $supplierId, string $id, array $data, User $user): array
    {
        $collection = $this->collections($supplierId)->where('c.id', $id)->select('c.*')->lockForUpdate()->first();
        abort_unless($collection, 404);
        if (in_array($collection->status, ['collected', 'dropped', 'received', 'cancelled'], true)) {
            SupplierPortal::fail('collection', 'RESPONSE_CLOSED');
        }
        $this->respond($supplierId, 'collection', $id, $data, $user);

        return $this->collectionDto($collection);
    }

    private function respond(string $supplierId, string $type, string $id, array $data, User $user): void
    {
        $responseId = Records::id();
        DB::table('supplier_responses')->insert([
            'id' => $responseId,
            'supplier_id' => $supplierId,
            'subject_type' => $type,
            'subject_id' => $id,
            'decision' => $data['decision'],
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'responded_by' => $user->id,
            'created_at' => now(),
        ]);
        Records::audit($user, 'supplier_portal.responded', $id, ['type' => $type, 'decision' => $data['decision']]);
        // The team sees the answer in its queues; nothing is confirmed on the supplier's word alone.
        Records::event('SupplierResponded', $responseId, [
            'supplier_id' => $supplierId,
            'subject_type' => $type,
            'subject_id' => $id,
            'decision' => $data['decision'],
        ]);
    }

    public function responses(string $type, string $id): array
    {
        return DB::table('supplier_responses')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($r) => [
                    'id' => $r->id,
                    'decision' => $r->decision,
                    'reference' => $r->reference,
                    'note' => $r->note,
                    'created_at' => Records::iso($r->created_at),
                ],
            )
            ->all();
    }

    /** Warranty terms of its offers and the after-sales cases attributed to it, with serials but no customer data. */
    public function warranties(string $supplierId): array
    {
        return [
            'cases' => DB::table('after_sales_cases AS a')
                ->join('order_lines AS ol', 'ol.id', '=', 'a.order_line_id')
                ->join('offers AS o', 'o.id', '=', 'ol.offer_id')
                ->join('shops AS s', 's.id', '=', 'o.shop_id')
                ->join('variants AS v', 'v.id', '=', 'o.variant_id')
                ->join('products AS pr', 'pr.id', '=', 'v.product_id')
                ->where('s.supplier_id', $supplierId)
                ->where('a.responsibility', 'supplier')
                ->orderByDesc('a.created_at')
                ->limit(100)
                ->get([
                    'a.number',
                    'a.kind',
                    'a.quantity',
                    'a.identifiers',
                    'a.status',
                    'a.resolution',
                    'a.responsibility_note',
                    'a.decided_at',
                    'a.created_at',
                    'v.sku',
                    'pr.name',
                ])
                ->map(
                    fn ($a) => [
                        'number' => $a->number,
                        'product' => json_decode($a->name, true),
                        'sku' => $a->sku,
                        'kind' => $a->kind,
                        'quantity' => (int) $a->quantity,
                        'identifiers' => json_decode($a->identifiers, true),
                        'status' => $a->status,
                        'resolution' => $a->resolution,
                        'finding' => $a->responsibility_note,
                        'decided_at' => Records::iso($a->decided_at),
                        'created_at' => Records::iso($a->created_at),
                    ],
                )
                ->all(),
            // Customers' units it repairs: serials and dates only.
            'repairs' => DB::table('after_sales_repairs AS r')
                ->join('after_sales_cases AS a', 'a.id', '=', 'r.case_id')
                ->where('r.supplier_id', $supplierId)
                ->orderByDesc('r.created_at')
                ->limit(100)
                ->get([
                    'r.number',
                    'r.status',
                    'r.outcome',
                    'r.identifiers',
                    'r.quantity',
                    'r.repairer_reference',
                    'r.sent_at',
                    'r.back_at',
                    'a.number AS case_number',
                ])
                ->map(
                    fn ($r) => [
                        'number' => $r->number,
                        'case' => $r->case_number,
                        'status' => $r->status,
                        'outcome' => $r->outcome,
                        'quantity' => (int) $r->quantity,
                        'identifiers' => json_decode($r->identifiers, true),
                        'reference' => $r->repairer_reference,
                        'sent_at' => Records::iso($r->sent_at),
                        'back_at' => Records::iso($r->back_at),
                    ],
                )
                ->all(),
            // Defective units sent back to it under warranty, and the answer recorded.
            'returns' => DB::table('after_sales_supplier_returns AS x')
                ->join('supplier_returns AS sr', 'sr.id', '=', 'x.supplier_return_id')
                ->join('after_sales_cases AS a', 'a.id', '=', 'x.case_id')
                ->where('x.supplier_id', $supplierId)
                ->orderByDesc('x.created_at')
                ->limit(100)
                ->get([
                    'a.number AS case_number',
                    'sr.quantity',
                    'sr.supplier_reference',
                    'x.decision',
                    'x.decided_at',
                    'x.created_at',
                ])
                ->map(
                    fn ($x) => [
                        'case' => $x->case_number,
                        'quantity' => (int) $x->quantity,
                        'reference' => $x->supplier_reference,
                        'decision' => $x->decision,
                        'decided_at' => Records::iso($x->decided_at),
                        'sent_at' => Records::iso($x->created_at),
                    ],
                )
                ->all(),
        ];
    }

    public function statement(string $supplierId, CarbonImmutable $at)
    {
        return DB::query()
            ->fromSub(app(SupplierLedger::class)->query($supplierId, $at), 'l')
            ->whereIn('kind', self::LEDGER_KINDS);
    }

    public function balances(string $supplierId, CarbonImmutable $at): array
    {
        return app(Payables::class)->balances($supplierId, $at);
    }

    /** Contracts the team approved for it (to accept) or already in force or ended; never drafts. */
    public function contracts(string $supplierId): array
    {
        return DB::table('supply_contracts')
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['approved', 'accepted', 'terminated'])
            ->orderByDesc('starts_on')
            ->get()
            ->map(function ($c) {
                $acceptance = $c->acceptance ? json_decode($c->acceptance, true) : null;

                return [
                    'id' => $c->id,
                    'reference' => $c->reference,
                    'status' => $c->status,
                    'starts_on' => $c->starts_on,
                    'ends_on' => $c->ends_on,
                    'terms' => json_decode($c->terms, true),
                    'text' => json_decode($c->text, true),
                    'acceptance' => $acceptance
                        ? array_intersect_key(
                            $acceptance,
                            array_flip(['signer_name', 'method', 'reference', 'accepted_at']),
                        )
                        : null,
                    'signed_document_id' => $c->signed_document_id,
                ];
            })
            ->all();
    }

    public function documents(string $supplierId): array
    {
        return DB::table('supplier_shared_documents AS d')
            ->join('private_documents AS p', 'p.id', '=', 'd.document_id')
            ->where('d.supplier_id', $supplierId)
            ->whereNull('d.revoked_at')
            ->orderByDesc('d.created_at')
            ->get([
                'd.id',
                'd.document_id',
                'd.title',
                'd.direction',
                'd.created_at',
                'p.mime',
                'p.bytes',
                'p.scan_status',
            ])
            ->map(
                fn ($d) => [
                    'id' => $d->id,
                    'document_id' => $d->document_id,
                    'title' => $d->title,
                    'direction' => $d->direction,
                    'mime' => $d->mime,
                    'bytes' => (int) $d->bytes,
                    'scan_status' => $d->scan_status,
                    'created_at' => Records::iso($d->created_at),
                ],
            )
            ->all();
    }

    /** A document it may open: shared with it and not withdrawn, or the signed copy of one of its visible contracts. */
    public function download(string $supplierId, string $documentId, User $user)
    {
        $shared = DB::table('supplier_shared_documents')
            ->where('supplier_id', $supplierId)
            ->where('document_id', $documentId)
            ->whereNull('revoked_at')
            ->exists();
        $contract = DB::table('supply_contracts')
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['accepted', 'terminated'])
            ->where('signed_document_id', $documentId)
            ->exists();
        abort_unless($shared || $contract, 404);

        return app(PrivateDocuments::class)->download(
            Records::row('private_documents', $documentId, false),
            $user,
            true,
        );
    }

    public function upload(string $supplierId, UploadedFile $file, string $title, User $user): array
    {
        $document = app(PrivateDocuments::class)->upload($file, 'supplier_portal', $supplierId, $user, true);
        DB::table('supplier_shared_documents')->insert([
            'id' => Records::id(),
            'supplier_id' => $supplierId,
            'document_id' => $document['id'],
            'title' => $title,
            'direction' => 'inbound',
            'shared_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $document;
    }

    public function share(string $supplierId, array $data, User $actor): array
    {
        Records::row('suppliers', $supplierId, false);
        $document = Records::row('private_documents', $data['document_id'], false);
        // Only a clean document already linked to this supplier (or to no supplier) can be shared with it.
        if ($document->scan_status !== 'clean' || ($document->supplier_id && $document->supplier_id !== $supplierId)) {
            Money::fail('document_id', 'DOCUMENT_NOT_APPROVED');
        }
        DB::table('supplier_shared_documents')->updateOrInsert(
            ['supplier_id' => $supplierId, 'document_id' => $document->id],
            [
                'id' => DB::table('supplier_shared_documents')
                    ->where('supplier_id', $supplierId)
                    ->where('document_id', $document->id)
                    ->value('id') ?? Records::id(),
                'title' => $data['title'],
                'direction' => 'outbound',
                'shared_by' => $actor->id,
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        Records::audit($actor, 'supplier_portal.document_shared', $document->id, ['supplier_id' => $supplierId]);

        return $this->documents($supplierId);
    }

    public function unshare(string $supplierId, string $shareId, User $actor): array
    {
        $updated = DB::table('supplier_shared_documents')
            ->where('id', $shareId)
            ->where('supplier_id', $supplierId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404);
        Records::audit($actor, 'supplier_portal.document_unshared', $shareId, ['supplier_id' => $supplierId]);

        return $this->documents($supplierId);
    }

    public function propose(string $supplierId, array $data, User $user): array
    {
        if (
            $data['kind'] === 'product' &&
            ! DB::table('shops')
                ->where('id', $data['shop_id'] ?? null)
                ->where('supplier_id', $supplierId)
                ->exists()
        ) {
            abort(404);
        }
        if ($data['kind'] === 'offer_update') {
            $this->ownOffer($supplierId, (string) ($data['offer_id'] ?? ''));
        }
        $id = Records::id();
        DB::table('supplier_proposals')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('supplier_proposal', 'PROP-', 6),
            'supplier_id' => $supplierId,
            'kind' => $data['kind'],
            'shop_id' => $data['kind'] === 'product' ? $data['shop_id'] : null,
            'offer_id' => $data['kind'] === 'offer_update' ? $data['offer_id'] : null,
            'payload' => Records::json($data['payload']),
            'submitted_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($user, 'supplier_portal.proposal_submitted', $id, ['kind' => $data['kind']]);

        return $this->proposalDto(Records::row('supplier_proposals', $id, false));
    }

    public function withdraw(string $supplierId, string $id, array $data, User $user): array
    {
        $proposal = DB::table('supplier_proposals')
            ->where('id', $id)
            ->where('supplier_id', $supplierId)
            ->lockForUpdate()
            ->first();
        abort_unless($proposal, 404);
        abort_unless((int) $proposal->version === $data['version'], 409);
        if (! in_array($proposal->status, ['submitted', 'in_review'], true)) {
            SupplierPortal::fail('proposal', 'PROPOSAL_STATE');
        }
        DB::table('supplier_proposals')
            ->where('id', $id)
            ->update(['status' => 'withdrawn', 'version' => $proposal->version + 1, 'updated_at' => now()]);
        Records::audit($user, 'supplier_portal.proposal_withdrawn', $id);

        return $this->proposalDto(Records::row('supplier_proposals', $id, false));
    }

    /**
     * The team's decision. Accepting a product proposal opens a product draft in the usual catalogue form
     * (category and address chosen by the team); prices, offers and publication stay separate team actions.
     */
    public function review(string $id, array $data, User $actor): array
    {
        $proposal = Records::row('supplier_proposals', $id);
        abort_unless((int) $proposal->version === $data['version'], 409);
        if (
            ! in_array($proposal->status, ['submitted', 'in_review'], true) ||
            ($data['decision'] === 'in_review' && $proposal->status === 'in_review')
        ) {
            SupplierPortal::fail('proposal', 'PROPOSAL_STATE');
        }
        $values = ['version' => $proposal->version + 1, 'review_note' => $data['note'] ?? null, 'updated_at' => now()];
        if ($data['decision'] === 'in_review') {
            $values['status'] = 'in_review';
        } else {
            $values += [
                'status' => $data['decision'] === 'accept' ? 'accepted' : 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ];
            if ($data['decision'] === 'accept' && $proposal->kind === 'product') {
                abort_unless(in_array('catalogue.write', $actor->permissions(), true), 403);
                if (empty($data['category_id']) || empty($data['slug'])) {
                    SupplierPortal::fail('category_id', 'PRODUCT_DRAFT_FIELDS');
                }
                $payload = json_decode($proposal->payload, true);
                $product = app(CatalogueManager::class)->save(
                    'products',
                    null,
                    null,
                    [
                        'category_id' => $data['category_id'],
                        'slug' => $data['slug'],
                        'name' => $payload['name'],
                        'description' => $payload['description'],
                        'variants' => [
                            [
                                'id' => null,
                                'sku' => $payload['sku'] ?? 'SUP-'.strtoupper(Str::random(10)),
                                'attributes' => [],
                            ],
                        ],
                    ],
                    $actor,
                );
                $values['product_id'] = $product['id'];
            }
        }
        DB::table('supplier_proposals')->where('id', $id)->update($values);
        Records::audit($actor, 'supplier_portal.proposal_'.$data['decision'], $id, ['note' => $data['note'] ?? null]);

        return $this->proposalDto(Records::row('supplier_proposals', $id, false));
    }

    public function proposalDto(object $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'supplier_id' => $p->supplier_id,
            'kind' => $p->kind,
            'shop_id' => $p->shop_id,
            'offer_id' => $p->offer_id,
            'payload' => json_decode($p->payload, true),
            'status' => $p->status,
            'review_note' => $p->review_note,
            'reviewed_at' => Records::iso($p->reviewed_at),
            'product_id' => $p->product_id,
            'version' => (int) $p->version,
            'created_at' => Records::iso($p->created_at),
        ];
    }
}
