<?php

namespace App\Http\Controllers;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InventoryController extends OperationalController
{
    public function warehouses(Request $request)
    {
        return $this->page(
            DB::table('warehouses')->orderBy('code'),
            $request,
            fn ($r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'address' => $r->address,
                'active' => (bool) $r->active,
            ],
        );
    }

    public function createWarehouse(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'active' => ['required', 'boolean'],
        ]);

        return $this->command(
            $request,
            'warehouse.create',
            $data,
            function ($d) use ($request) {
                $id = Records::id();
                DB::table('warehouses')->insert(['id' => $id, ...$d, 'created_at' => now(), 'updated_at' => now()]);
                Records::audit($request->user(), 'warehouse.created', $id);

                return ['id' => $id, ...$d];
            },
            201,
        );
    }

    public function locations(Request $request)
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'uuid']]);
        $q = DB::table('stock_locations')->orderBy('code');
        if (! empty($data['warehouse_id'])) {
            $q->where('warehouse_id', $data['warehouse_id']);
        }

        return $this->page(
            $q,
            $request,
            fn ($r) => [
                'id' => $r->id,
                'warehouse_id' => $r->warehouse_id,
                'code' => $r->code,
                'name' => $r->name,
                'active' => (bool) $r->active,
            ],
        );
    }

    public function createLocation(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'active' => ['required', 'boolean'],
        ]);

        return $this->command(
            $request,
            'location.create',
            $data,
            function ($d) use ($request) {
                Records::row('warehouses', $d['warehouse_id']);
                $id = Records::id();
                DB::table('stock_locations')->insert([
                    'id' => $id,
                    ...$d,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Records::audit($request->user(), 'location.created', $id);

                return ['id' => $id, ...$d];
            },
            201,
        );
    }

    public function profile(Request $request, string $variantId)
    {
        Records::row('variants', $variantId, false);
        $row = DB::table('inventory_profiles')->where('variant_id', $variantId)->first();

        return ['data' => $row ? $this->profileDto($row) : null];
    }

    public function saveProfile(Request $request, string $variantId)
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'tracking_mode' => ['required', 'in:none,lot,serial,imei'],
            'requires_expiry' => ['required', 'boolean'],
            'logistics' => ['required', 'array:mass_grams,length_mm,width_mm,height_mm,transport_class,handling'],
            'logistics.mass_grams' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'logistics.length_mm' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'logistics.width_mm' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'logistics.height_mm' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'logistics.transport_class' => ['required', 'in:standard,bulky,fragile,temperature_controlled,unavailable'],
            'logistics.handling' => ['required', 'array:fr,en'],
            'logistics.handling.fr' => ['nullable', 'string', 'max:2000'],
            'logistics.handling.en' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->command($request, 'inventory.profile:'.$variantId, $data, function ($d) use (
            $request,
            $variantId,
        ) {
            Records::row('variants', $variantId);
            $row = DB::table('inventory_profiles')->where('variant_id', $variantId)->lockForUpdate()->first();
            abort_unless($row ? (int) $row->version === ($d['version'] ?? null) : empty($d['version']), 409);
            $id = $row?->id ?? Records::id();
            DB::table('inventory_profiles')->updateOrInsert(
                ['variant_id' => $variantId],
                [
                    'id' => $id,
                    'tracking_mode' => $d['tracking_mode'],
                    'requires_expiry' => $d['requires_expiry'],
                    'logistics' => Records::json($d['logistics']),
                    'version' => $row ? $row->version + 1 : 1,
                    'verified_by' => $request->user()->id,
                    'created_at' => $row?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            Records::audit($request->user(), 'inventory.profile_verified', $id, ['profile' => $d]);

            return $this->profileDto(Records::row('inventory_profiles', $id));
        });
    }

    private function profileDto(object $r): array
    {
        return [
            'id' => $r->id,
            'variant_id' => $r->variant_id,
            'version' => (int) $r->version,
            'tracking_mode' => $r->tracking_mode,
            'requires_expiry' => (bool) $r->requires_expiry,
            'logistics' => json_decode($r->logistics, true),
        ];
    }

    public function settings()
    {
        $r = DB::table('business_settings')->where('key', 'inventory')->first();

        return ['data' => $r ? ['version' => (int) $r->version, ...json_decode($r->value, true)] : null];
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'reservation_ttl_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command($request, 'inventory.settings', $data, function ($d) use ($request) {
            $r = DB::table('business_settings')->where('key', 'inventory')->lockForUpdate()->first();
            abort_unless($r ? (int) $r->version === ($d['version'] ?? null) : empty($d['version']), 409);
            $version = $r ? $r->version + 1 : 1;
            DB::table('business_settings')->updateOrInsert(
                ['key' => 'inventory'],
                [
                    'value' => Records::json(['reservation_ttl_seconds' => $d['reservation_ttl_seconds']]),
                    'version' => $version,
                    'updated_by' => $request->user()->id,
                    'created_at' => $r?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            Records::audit($request->user(), 'inventory.policy_updated', 'inventory', ['version' => $version, ...$d]);

            return ['version' => $version, 'reservation_ttl_seconds' => $d['reservation_ttl_seconds']];
        });
    }

    public function balances(Request $request, Inventory $inventory)
    {
        $data = $request->validate(['lot_id' => ['nullable', 'uuid'], 'location_id' => ['nullable', 'uuid']]);
        $q = DB::table('inventory_balances')->orderBy('id');
        foreach (['lot_id', 'location_id'] as $f) {
            if (! empty($data[$f])) {
                $q->where($f, $data[$f]);
            }
        }

        return $this->page($q, $request, fn ($r) => $inventory->balanceDto($r));
    }

    public function units(Request $request)
    {
        $data = $request->validate(['lot_id' => ['required', 'uuid']]);

        return $this->page(
            DB::table('inventory_units')->where('lot_id', $data['lot_id'])->orderBy('identifier'),
            $request,
            fn ($r) => [
                'id' => $r->id,
                'lot_id' => $r->lot_id,
                'balance_id' => $r->balance_id,
                'identifier_type' => $r->identifier_type,
                'identifier' => $r->identifier,
                'status' => $r->status,
            ],
        );
    }

    public function movements(Request $request)
    {
        $data = $request->validate(['balance_id' => ['nullable', 'uuid']]);
        $q = DB::table('inventory_movements')->orderByDesc('created_at')->orderBy('id');
        if (! empty($data['balance_id'])) {
            $q->where('balance_id', $data['balance_id']);
        }

        return $this->page(
            $q,
            $request,
            fn ($r) => [
                'id' => $r->id,
                'balance_id' => $r->balance_id,
                'quantity' => (int) $r->quantity,
                'kind' => $r->kind,
                'origin_type' => $r->origin_type,
                'origin_id' => $r->origin_id,
                'reason' => $r->reason,
                'document_id' => $r->document_id,
                'units' => json_decode($r->units, true),
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function receivablePurchases(Request $request)
    {
        return $this->page(
            DB::table('purchase_orders')
                ->whereIn('status', ['draft', 'confirmed'])
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $request,
            fn ($r) => [
                'id' => $r->id,
                'supplier_id' => $r->supplier_id,
                'reference' => $r->reference,
                'status' => $r->status,
                'lines' => DB::table('purchase_lines')
                    ->where('purchase_order_id', $r->id)
                    ->get()
                    ->map(
                        fn ($l) => [
                            'id' => $l->id,
                            'offer_id' => $l->offer_id,
                            'quantity' => (int) $l->quantity,
                            'received_quantity' => (int) $l->received_quantity,
                            'tracking_mode' => $l->tracking_mode,
                            'requires_expiry' => (bool) $l->requires_expiry,
                        ],
                    )
                    ->all(),
            ],
        );
    }

    public function plan(Request $request, string $id, Inventory $inventory)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:1000000']]);

        return DB::transaction(fn () => ['data' => $inventory->plan($id, $data['quantity'], $request->user())], 3);
    }

    public function reserve(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'offer_id' => ['required', 'uuid'],
            'purpose_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $this->command(
            $request,
            'inventory.reserve',
            $data,
            fn ($d) => $inventory->reserve($d, $request->user()),
            201,
        );
    }

    public function release(Request $request, string $id, Inventory $inventory)
    {
        return $this->command(
            $request,
            'inventory.release:'.$id,
            [],
            fn () => $inventory->release($id, $request->user()),
        );
    }

    public function issue(Request $request, string $id, Inventory $inventory)
    {
        $data = $request->validate([
            'sale_reference' => ['required', 'string', 'max:150'],
            'sale_verified' => ['required', 'accepted'],
            'document_id' => ['required', 'uuid'],
        ]);

        return $this->command($request, 'inventory.issue:'.$id, $data, function ($d) use ($request, $id, $inventory) {
            $r = Records::row('inventory_reservations', $id, false);
            if ($r->order_line_id) {
                Money::fail('reservation', 'ORDER_RESERVATION_PROTECTED');
            }
            $context = $inventory->offerTerms($r->offer_id, false);
            app(PrivateDocuments::class)->requireClean($d['document_id'], $context['shop']->supplier_id, [
                'stock_evidence',
            ]);

            return $inventory->consume($id, $d['sale_reference'], $request->user(), $d['document_id']);
        });
    }

    public function move(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'balance_id' => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'condition' => ['required', 'in:available,quarantine,damaged,returned'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_ids' => ['present', 'array', 'max:10000'],
            'unit_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:1000'],
            'document_id' => ['required', 'uuid'],
        ]);

        return $this->command($request, 'inventory.move', $data, function ($d, $commandId) use ($request, $inventory) {
            $this->evidenceForBalance($d['balance_id'], $d['document_id']);

            return $inventory->move($d, $commandId, $request->user());
        });
    }

    public function adjust(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'balance_id' => ['required', 'uuid'],
            'delta' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'max:1000'],
            'document_id' => ['required', 'uuid'],
        ]);

        return $this->command($request, 'inventory.adjust', $data, function ($d, $commandId) use (
            $request,
            $inventory,
        ) {
            $this->evidenceForBalance($d['balance_id'], $d['document_id']);

            return $inventory->adjust($d, $commandId, $request->user());
        });
    }

    public function supplierReturn(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'balance_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:1000'],
            'supplier_reference' => ['required', 'string', 'max:150'],
            'document_id' => ['required', 'uuid'],
            'unit_ids' => ['present', 'array', 'max:10000'],
            'unit_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return $this->command(
            $request,
            'inventory.supplier_return',
            $data,
            function ($d) use ($request, $inventory) {
                $this->evidenceForBalance($d['balance_id'], $d['document_id']);

                return $inventory->returnToSupplier($d, $request->user());
            },
            201,
        );
    }

    private function evidenceForBalance(string $balanceId, string $documentId): void
    {
        $b = Records::row('inventory_balances', $balanceId, false);
        $lot = Records::row('stock_lots', $b->lot_id, false);
        app(PrivateDocuments::class)->requireClean($documentId, $lot->supplier_id, ['stock_evidence']);
    }

    public function count(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'balance_id' => ['required', 'uuid'],
            'counted_quantity' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'observed_unit_ids' => ['present', 'array', 'max:10000'],
            'observed_unit_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:1000'],
            'document_id' => ['required', 'uuid'],
        ]);

        return $this->command($request, 'inventory.count', $data, function ($d, $commandId) use ($request, $inventory) {
            $this->evidenceForBalance($d['balance_id'], $d['document_id']);

            return $inventory->count($d, $commandId, $request->user());
        });
    }
}
