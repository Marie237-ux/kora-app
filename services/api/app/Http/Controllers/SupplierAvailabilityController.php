<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Inventory;
use App\Domain\Orders\OrderSupply;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplierAvailabilityController extends OperationalController
{
    public function show(string $offerId)
    {
        Records::row('offers', $offerId, false);
        $r = DB::table('supplier_availability')->where('offer_id', $offerId)->first();

        return ['data' => $r ? $this->dto($r) : null];
    }

    public function save(Request $request, string $offerId, Inventory $inventory)
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'verified_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'valid_until' => ['required', 'date', 'after:verified_at', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'preparation_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'contact' => ['required', 'string', 'max:150'],
            'reference' => ['required', 'string', 'max:150'],
        ]);

        return $this->command($request, 'supplier_availability:'.$offerId, $data, function ($d) use (
            $request,
            $offerId,
            $inventory,
        ) {
            $inventory->offerTerms($offerId);
            $r = DB::table('supplier_availability')->where('offer_id', $offerId)->lockForUpdate()->first();
            abort_unless($r ? (int) $r->version === ($d['version'] ?? null) : empty($d['version']), 409);
            $id = $r?->id ?? Records::id();
            DB::table('supplier_availability')->updateOrInsert(
                ['offer_id' => $offerId],
                [
                    'id' => $id,
                    'quantity' => $d['quantity'] ?? null,
                    'verified_at' => CarbonImmutable::parse($d['verified_at'])->utc(),
                    'valid_until' => CarbonImmutable::parse($d['valid_until'])->utc(),
                    'preparation_minutes' => $d['preparation_minutes'],
                    'contact' => $d['contact'],
                    'reference' => $d['reference'],
                    'verified_by' => $request->user()->id,
                    'version' => $r ? $r->version + 1 : 1,
                    'created_at' => $r?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            Records::audit($request->user(), 'supplier_availability.verified', $id, [
                'quantity' => $d['quantity'] ?? null,
            ]);

            return $this->dto(Records::row('supplier_availability', $id));
        });
    }

    public function requestReservation(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'offer_id' => ['required', 'uuid'],
            'purpose_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['required', 'date', 'after:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);

        return $this->command(
            $request,
            'supplier_reservation.request',
            $data,
            function ($d) use ($request, $inventory) {
                if (DB::table('order_lines')->where('id', $d['purpose_id'])->exists()) {
                    OrderSupply::fail('purpose_id', 'LINKED_ROUTE_REQUIRED');
                }
                $inventory->offerTerms($d['offer_id']);
                $id = Records::id();
                DB::table('supplier_reservations')->insert([
                    'id' => $id,
                    'offer_id' => $d['offer_id'],
                    'purpose_id' => $d['purpose_id'],
                    'quantity' => $d['quantity'],
                    'status' => 'requested',
                    'expires_at' => CarbonImmutable::parse($d['expires_at'])->utc(),
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Records::audit($request->user(), 'supplier_reservation.requested', $id);

                return $this->reservationDto(Records::row('supplier_reservations', $id));
            },
            201,
        );
    }

    public function reservations(Request $request)
    {
        $data = $request->validate(['offer_id' => ['nullable', 'uuid']]);
        $q = DB::table('supplier_reservations')->orderByDesc('created_at')->orderBy('id');
        if (! empty($data['offer_id'])) {
            $q->where('offer_id', $data['offer_id']);
        }

        return $this->page($q, $request, fn ($r) => $this->reservationDto($r));
    }

    public function transition(Request $request, string $id, string $action)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:150'],
            'confirmed_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $this->command($request, 'supplier_reservation.'.$action.':'.$id, $data, function ($d) use (
            $request,
            $id,
            $action,
        ) {
            $initial = Records::row('supplier_reservations', $id, false);
            if ($initial->order_line_id) {
                return $this->reservationDto(app(OrderSupply::class)->transition($id, $action, $d, $request->user()));
            }
            if (isset($d['confirmed_quantity']) && (int) $d['confirmed_quantity'] !== (int) $initial->quantity) {
                OrderSupply::fail('confirmed_quantity', 'QUANTITY_INVALID');
            }
            $r = Records::row('supplier_reservations', $id);
            if (CarbonImmutable::parse($r->expires_at, 'UTC')->lessThanOrEqualTo(now())) {
                Money::fail('reservation', 'RESERVATION_EXPIRED');
            }
            $next = match (true) {
                $action === 'confirm' && $r->status === 'requested' => 'confirmed',
                $action === 'reject' && $r->status === 'requested' => 'rejected',
                $action === 'request-release' && in_array($r->status, ['requested', 'confirmed'], true) => 'release_requested',
                $action === 'confirm-release' && $r->status === 'release_requested' => 'released',
                default => null,
            };
            if (! $next) {
                Money::fail('status', 'RESERVATION_STATE');
            }
            $values = ['status' => $next, 'updated_at' => now()];
            if ($next === 'confirmed') {
                $values += [
                    'confirmation_reference' => $d['reference'],
                    'confirmed_at' => now(),
                    'confirmed_quantity' => $r->quantity,
                ];
            }
            DB::table('supplier_reservations')->where('id', $id)->update($values);
            Records::audit($request->user(), 'supplier_reservation.'.$next, $id, ['reference' => $d['reference']]);

            return $this->reservationDto(Records::row('supplier_reservations', $id));
        });
    }

    private function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'offer_id' => $r->offer_id,
            'quantity' => $r->quantity === null ? null : (int) $r->quantity,
            'verified_at' => Records::iso($r->verified_at),
            'valid_until' => Records::iso($r->valid_until),
            'fresh' => CarbonImmutable::parse($r->valid_until, 'UTC')->isFuture(),
            'preparation_minutes' => (int) $r->preparation_minutes,
            'contact' => $r->contact,
            'reference' => $r->reference,
            'version' => (int) $r->version,
        ];
    }

    private function reservationDto(object $r): array
    {
        $fresh = CarbonImmutable::parse($r->expires_at, 'UTC')->isFuture();

        return [
            'id' => $r->id,
            'offer_id' => $r->offer_id,
            'purpose_id' => $r->purpose_id,
            'order_line_id' => $r->order_line_id,
            'confirmed_quantity' => $r->confirmed_quantity === null ? null : (int) $r->confirmed_quantity,
            'quantity' => (int) $r->quantity,
            'status' => $fresh ? $r->status : 'expired',
            'supplier_confirmed' => $fresh && $r->status === 'confirmed',
            'confirmation_reference' => $r->confirmation_reference,
            'confirmed_at' => Records::iso($r->confirmed_at),
            'expires_at' => Records::iso($r->expires_at),
        ];
    }
}
