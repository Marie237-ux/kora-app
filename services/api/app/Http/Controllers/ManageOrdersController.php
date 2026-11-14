<?php

namespace App\Http\Controllers;

use App\Domain\Orders\Orders;
use App\Domain\Payments\PaymentIncidents;
use App\Domain\Payments\Payments;
use App\Domain\Payments\Refunds;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManageOrdersController extends OperationalController
{
    public function index(Request $http, Orders $orders)
    {
        $d = $http->validate(['status' => ['nullable', 'in:awaiting_payment,payment_review,paid,expired,cancelled']]);

        return $this->page(
            DB::table('orders')
                ->when($d['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $http,
            fn ($order) => $orders->dto($order),
        );
    }

    /** Internal detail: the customer view plus cost snapshots, sources, attempts, incidents and history. */
    public function show(string $id, Orders $orders, Payments $payments)
    {
        $order = Records::row('orders', $id, false);

        return [
            'data' => [
                ...$orders->dto($order),
                'internal_lines' => DB::table('order_lines')
                    ->where('order_id', $id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get()
                    ->map(
                        fn ($line) => [
                            'id' => $line->id,
                            'from_warehouse' => (int) $line->from_warehouse,
                            'from_supplier' => (int) $line->from_supplier,
                            'reservation_id' => $line->reservation_id,
                            'supply_status' => $line->supply_status,
                            'supply_version' => (int) $line->supply_version,
                            'supplier_reservation_id' => $line->supplier_reservation_id,
                            'supplier_confirmed_quantity' => (int) $line->supplier_confirmed_quantity,
                            'internal' => json_decode($line->internal_snapshot, true),
                        ],
                    ),
                'attempts' => DB::table('payment_attempts')
                    ->where('order_id', $id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(
                        fn ($attempt) => [
                            ...$payments->attemptDto($attempt),
                            'provider_transaction_id' => $attempt->provider_transaction_id,
                            'provider_status' => $attempt->provider_status,
                            'testmode' => $attempt->testmode === null ? null : (bool) $attempt->testmode,
                            'failure_reason' => $attempt->failure_reason,
                            'checks' => (int) $attempt->checks,
                            'confirmed_at' => Records::iso($attempt->confirmed_at),
                        ],
                    ),
                'incidents' => DB::table('payment_incidents')
                    ->where('order_id', $id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn ($i) => $this->incidentDto($i)),
                'events' => DB::table('order_events')->where('order_id', $id)->orderBy('id')->get()->map(
                    fn ($e) => [
                        'action' => $e->action,
                        'actor_id' => $e->actor_id,
                        'data' => json_decode($e->data, true),
                        'created_at' => Records::iso($e->created_at),
                    ],
                ),
            ],
        ];
    }

    public function incidents(Request $http)
    {
        $d = $http->validate(['status' => ['nullable', 'in:open,resolved']]);

        return $this->page(
            DB::table('payment_incidents')
                ->where('status', $d['status'] ?? 'open')
                ->orderBy('created_at')
                ->orderBy('id'),
            $http,
            fn ($i) => $this->incidentDto($i),
        );
    }

    /** Manual reconciliation of an attempt whose provider transaction is known. */
    public function check(string $attemptId, Payments $payments)
    {
        $attempt = Records::row('payment_attempts', $attemptId, false);
        abort_unless($attempt->provider_transaction_id, 422);

        return ['data' => ['outcome' => $payments->verify($attempt->id, $attempt->provider_transaction_id)]];
    }

    private function incidentDto(object $incident): array
    {
        return app(PaymentIncidents::class)->dto($incident);
    }

    /** Locks are always taken order first, then refund or incident, as in payment confirmation. */
    public function resolveIncident(Request $http, string $id, PaymentIncidents $incidents)
    {
        $d = $http->validate([
            'outcome' => ['required', 'in:confirmed_after_review,dismissed'],
            'note' => ['required', 'string', 'max:2000'],
            'evidence_id' => ['nullable', 'uuid'],
        ]);

        return $this->command($http, 'payment.incident_resolve:'.$id, $d, function ($data) use (
            $http,
            $id,
            $incidents,
        ) {
            $orderId = DB::table('payment_incidents')->where('id', $id)->value('order_id');
            abort_unless($orderId, 404);
            Records::row('orders', $orderId);

            return $incidents->resolve(
                Records::row('payment_incidents', $id),
                $data['outcome'],
                $data['note'],
                $data['evidence_id'] ?? null,
                $http->user(),
            );
        });
    }

    public function refunds(Request $http, Refunds $refunds)
    {
        $d = $http->validate(['status' => ['nullable', 'in:requested,paid,cancelled']]);

        return $this->page(
            DB::table('customer_refunds')
                ->when($d['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $http,
            fn ($r) => $refunds->dto($r),
        );
    }

    public function requestRefund(Request $http, string $id, Refunds $refunds)
    {
        $d = $http->validate([
            'amount' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:300'],
            'incident_id' => ['nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'refund.request:'.$id,
            $d,
            fn ($data) => $refunds->request(
                Records::row('orders', $id),
                Money::parse($data['amount']),
                $data['reason'],
                $data['incident_id'] ?? null,
                $http->user(),
            ),
            201,
        );
    }

    public function verifyRefund(Request $http, string $id, Refunds $refunds)
    {
        $d = $http->validate([
            'amount' => ['required', 'string'],
            'channel' => ['required', 'in:'.implode(',', Refunds::CHANNELS)],
            'reference' => ['required', 'string', 'max:150'],
            'evidence_id' => ['required', 'uuid'],
            'paid_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);

        return $this->command($http, 'refund.verify:'.$id, $d, function ($data) use ($http, $id, $refunds) {
            Records::row('orders', $this->refundOrder($id));

            return $refunds->verify(Records::row('customer_refunds', $id), $data, $http->user());
        });
    }

    public function cancelRefund(Request $http, string $id, Refunds $refunds)
    {
        $d = $http->validate(['reason' => ['required', 'string', 'max:300']]);

        return $this->command($http, 'refund.cancel:'.$id, $d, function ($data) use ($http, $id, $refunds) {
            Records::row('orders', $this->refundOrder($id));

            return $refunds->cancel(Records::row('customer_refunds', $id), $data['reason'], $http->user());
        });
    }

    private function refundOrder(string $refundId): string
    {
        $orderId = DB::table('customer_refunds')->where('id', $refundId)->value('order_id');
        abort_unless($orderId, 404);

        return $orderId;
    }
}
