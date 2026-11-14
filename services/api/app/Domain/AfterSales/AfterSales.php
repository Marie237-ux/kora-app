<?php

namespace App\Domain\AfterSales;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Loyalty\LoyaltyRefundAttribution;
use App\Domain\Orders\OrderPreparation;
use App\Domain\Payments\Refunds;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * After-sales cases on delivered order lines (§25). KORA stays the customer's single contact: it proposes a
 * remedy (exchange, repair, return and refund, refund, other), the customer accepts or declines it, and the
 * case is resolved only once the remedy really happened (refund paid, returned units received and controlled).
 * A delivery confirmed by code never waives a defect found later; a quick report is noted, never required.
 */
final class AfterSales
{
    public const KINDS = ['missing', 'damaged', 'non_conforming', 'breakdown', 'other'];

    public const RESOLUTIONS = ['exchange', 'repair', 'return_refund', 'refund', 'other'];

    public const RESPONSIBILITIES = ['supplier', 'carrier', 'kora', 'customer', 'undetermined'];

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('after_sales.'.$code)]);
    }

    /** What the customer bought and received, with the warranty sold with it and the serials delivered. */
    public function purchases(User $customer): array
    {
        $locale = app()->getLocale();

        return DB::table('orders')
            ->where('user_id', $customer->id)
            ->whereNotNull('delivered_at')
            ->orderByDesc('delivered_at')
            ->get()
            ->map(
                fn ($order) => [
                    'order_id' => $order->id,
                    'number' => $order->number,
                    'delivered_at' => Records::iso($order->delivered_at),
                    'lines' => DB::table('order_lines')
                        ->where('order_id', $order->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get()
                        ->map(function ($line) use ($locale) {
                            $snapshot = json_decode($line->snapshot, true);

                            return [
                                'order_line_id' => $line->id,
                                'name' => $snapshot['name'][$locale] ?? $snapshot['name']['fr'],
                                'sku' => $snapshot['sku'],
                                'shop' => $snapshot['shop']['name'] ?? null,
                                'warranty' => $snapshot['warranty'][$locale] ?? ($snapshot['warranty']['fr'] ?? null),
                                'delivered_quantity' => $this->delivered($line->id)['quantity'],
                                'identifiers' => $this->delivered($line->id)['identifiers'],
                                'open_cases' => DB::table('after_sales_cases')
                                    ->where('order_line_id', $line->id)
                                    ->whereNotIn('status', ['resolved', 'rejected'])
                                    ->count(),
                            ];
                        })
                        ->values(),
                ],
            )
            ->values()
            ->all();
    }

    /** Units actually handed over for the line: what left stock for it, net of replaced units. */
    private function delivered(string $lineId): array
    {
        $line = DB::table('order_lines')->where('id', $lineId)->first();

        return app(OrderPreparation::class)->issued($line->order_id)[$lineId] ?? ['quantity' => 0, 'identifiers' => []];
    }

    public function open(array $data, User $customer): array
    {
        $line = DB::table('order_lines')->where('id', $data['order_line_id'])->first();
        abort_unless($line, 404);
        $order = Records::row('orders', $line->order_id);
        abort_unless((int) $order->user_id === $customer->id, 404);
        if (! $order->delivered_at) {
            self::fail('order_line_id', 'NOT_DELIVERED');
        }
        $delivered = $this->delivered($line->id);
        if ($data['quantity'] > $delivered['quantity']) {
            self::fail('quantity', 'QUANTITY_INVALID');
        }
        $identifiers = $data['identifiers'] ?? [];
        sort($identifiers);
        // Serialised items: the customer names exactly which delivered units are concerned.
        if (
            array_diff($identifiers, $delivered['identifiers']) ||
            ($delivered['identifiers'] && count($identifiers) !== $data['quantity'])
        ) {
            self::fail('identifiers', 'IDENTIFIERS_INVALID');
        }
        $id = Records::id();
        DB::table('after_sales_cases')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('after_sales_case', 'SAV-', 7),
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'user_id' => $customer->id,
            'kind' => $data['kind'],
            'quantity' => $data['quantity'],
            'identifiers' => Records::json($identifiers),
            'description' => $data['description'],
            'reported_quickly' => CarbonImmutable::parse($order->delivered_at, 'UTC')->addDay()->isFuture(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->event($id, $customer, 'opened', ['kind' => $data['kind'], 'quantity' => $data['quantity']]);

        return $this->customerView(Records::row('after_sales_cases', $id, false));
    }

    public function owned(string $id, User $customer, bool $lock = false): object
    {
        $case = Records::row('after_sales_cases', $id, $lock);
        abort_unless((int) $case->user_id === $customer->id, 404);

        return $case;
    }

    public function message(string $id, array $data, User $actor, bool $staff): array
    {
        $case = $staff ? Records::row('after_sales_cases', $id) : $this->owned($id, $actor, true);
        $visibility = $staff ? $data['visibility'] : 'customer';
        $this->post($case, $actor, $visibility, $data['body'], null);
        if ($staff && $case->status === 'open' && $visibility === 'customer') {
            $this->update($case, ['status' => 'in_review']);
        }

        return $staff
            ? $this->staffView(Records::row('after_sales_cases', $case->id, false))
            : $this->customerView(Records::row('after_sales_cases', $case->id, false));
    }

    /** Customer photo or document, private, scanned, attached to the conversation. */
    public function attach(string $id, UploadedFile $file, User $customer): array
    {
        $case = $this->owned($id, $customer);
        if (in_array($case->status, ['resolved', 'rejected'], true)) {
            self::fail('case', 'CASE_CLOSED');
        }
        $document = app(PrivateDocuments::class)->upload($file, 'claim_evidence', null, $customer, true);

        return DB::transaction(function () use ($id, $customer, $document) {
            $case = $this->owned($id, $customer, true);
            $this->post($case, $customer, 'customer', null, $document['id']);

            return $this->customerView(Records::row('after_sales_cases', $case->id, false));
        }, 3);
    }

    public function assign(string $id, array $data, User $actor): array
    {
        $case = $this->lock($id, $data['version'], ['open', 'in_review', 'proposed', 'declined', 'accepted']);
        $assignee = User::query()->find($data['assignee_id']);
        if (! $assignee || ! in_array('support.write', $assignee->permissions(), true)) {
            self::fail('assignee_id', 'ASSIGNEE_INVALID');
        }
        $this->update($case, [
            'assignee_id' => $assignee->id,
            'status' => $case->status === 'open' ? 'in_review' : $case->status,
        ]);
        $this->event($case->id, $actor, 'assigned', ['assignee_id' => $assignee->id]);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    /**
     * Remedy proposed to the customer, with the responsibility found on the facts. A refund defaults to the
     * exact amount paid for the units (loyalty discount share deducted); a partial refund may be lower.
     */
    public function propose(string $id, array $data, User $actor): array
    {
        $case = $this->lock($id, $data['version'], ['open', 'in_review', 'declined']);
        $amount = null;
        if (in_array($data['resolution'], ['return_refund', 'refund'], true)) {
            $line = DB::table('order_lines')->where('id', $case->order_line_id)->first();
            $order = DB::table('orders')->where('id', $case->order_id)->first();
            $paid = (int) app(LoyaltyRefundAttribution::class)->nextSlice($line, $case->quantity)['amount'];
            $amount = $data['amount'] ?? $paid;
            $left = app(Refunds::class)->collected($order->id) - app(Refunds::class)->committed($order->id);
            if ($amount < 1 || $amount > $paid || $amount > $left) {
                self::fail('amount', 'AMOUNT_INVALID');
            }
        } elseif (isset($data['amount'])) {
            self::fail('amount', 'AMOUNT_INVALID');
        }
        $this->update($case, [
            'status' => 'proposed',
            'resolution' => $data['resolution'],
            'resolution_amount' => $amount,
            'responsibility' => $data['responsibility'],
            'responsibility_note' => $data['responsibility_note'],
            'decided_at' => now(),
        ]);
        $this->post($case, $actor, 'customer', $data['message'], null);
        $this->event($case->id, $actor, 'proposed', [
            'resolution' => $data['resolution'],
            'amount' => $amount,
            'responsibility' => $data['responsibility'],
        ]);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    public function decide(string $id, array $data, User $customer): array
    {
        $case = $this->owned($id, $customer, true);
        abort_unless((int) $case->version === $data['version'], 409);
        if ($case->status !== 'proposed') {
            self::fail('case', 'CASE_STATE');
        }
        $this->update($case, ['status' => $data['accept'] ? 'accepted' : 'declined']);
        if (! blank($data['note'] ?? null)) {
            $this->post($case, $customer, 'customer', $data['note'], null);
        }
        $this->event($case->id, $customer, $data['accept'] ? 'accepted' : 'declined', []);

        return $this->customerView(Records::row('after_sales_cases', $case->id, false));
    }

    public function reject(string $id, array $data, User $actor): array
    {
        $case = $this->lock($id, $data['version'], ['open', 'in_review', 'declined']);
        $this->update($case, ['status' => 'rejected', 'closed_at' => now()]);
        $this->post($case, $actor, 'customer', $data['message'], null);
        $this->event($case->id, $actor, 'rejected', []);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    /** The refund the customer accepted, requested through the usual refund flow and capped by what was paid. */
    public function refund(string $id, array $data, User $actor): array
    {
        $initial = Records::row('after_sales_cases', $id, false);
        $order = Records::row('orders', $initial->order_id);
        $case = $this->lock($id, $data['version'], ['accepted']);
        if (! in_array($case->resolution, ['return_refund', 'refund'], true) || $case->refund_id) {
            self::fail('case', 'CASE_STATE');
        }
        if ($case->resolution === 'return_refund' && ! $this->returnedQuantity($case->id)) {
            self::fail('case', 'RETURN_REQUIRED');
        }
        $refund = app(Refunds::class)->request(
            $order,
            (int) $case->resolution_amount,
            'After-sales '.$case->number.' / SAV '.$case->number,
            null,
            $actor,
        );
        $line = DB::table('order_lines')->where('id', $case->order_line_id)->lockForUpdate()->first();
        app(LoyaltyRefundAttribution::class)->reserveReturn($order, $line, $refund, (int) $case->quantity);
        $this->update($case, ['refund_id' => $refund['id']]);
        $this->event($case->id, $actor, 'refund_requested', [
            'refund_id' => $refund['id'],
            'amount' => $refund['amount'],
        ]);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    /** Units handed back by the customer, into the "returned" condition of a KORA location, pending control. */
    public function receiveReturn(string $id, array $data, User $actor): array
    {
        $case = $this->lock($id, $data['version'], ['accepted']);
        if (! in_array($case->resolution, ['return_refund', 'exchange', 'repair'], true)) {
            self::fail('case', 'CASE_STATE');
        }
        if ($this->returnedQuantity($case->id) + $data['quantity'] > $case->quantity) {
            self::fail('quantity', 'QUANTITY_INVALID');
        }
        $identifiers = $data['identifiers'] ?? [];
        $claimed = json_decode($case->identifiers, true);
        if (array_diff($identifiers, $claimed) || ($claimed && count($identifiers) !== $data['quantity'])) {
            self::fail('identifiers', 'IDENTIFIERS_INVALID');
        }
        [$lots, $units] = $this->soldLots($case, $identifiers, $data['quantity']);
        $balances = app(Inventory::class)->customerReturn(
            $lots,
            $units,
            $data['location_id'],
            $case->id,
            'After-sales '.$case->number,
            $actor,
        );
        $created = [];
        foreach ($balances as $b) {
            $rid = Records::id();
            DB::table('after_sales_returns')->insert([
                'id' => $rid,
                'case_id' => $case->id,
                'balance_id' => $b['balance_id'],
                'quantity' => $b['quantity'],
                'identifiers' => Records::json(
                    DB::table('inventory_units')
                        ->whereIn('id', $b['unit_ids'])
                        ->orderBy('identifier')
                        ->pluck('identifier')
                        ->all(),
                ),
                'note' => $data['note'] ?? null,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $created[] = $rid;
        }
        $this->update($case, []);
        $this->event($case->id, $actor, 'return_received', ['returns' => $created, 'quantity' => $data['quantity']]);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    /** Lots the units were sold from: exact units for serials, otherwise the line's picks in order. */
    private function soldLots(object $case, array $identifiers, int $quantity): array
    {
        $line = DB::table('order_lines')->where('id', $case->order_line_id)->first();
        if ($identifiers) {
            $units = DB::table('inventory_units')
                ->whereIn('identifier', $identifiers)
                ->where('variant_id', DB::table('offers')->where('id', $line->offer_id)->value('variant_id'))
                ->where('status', 'consumed')
                ->get();
            if ($units->count() !== count($identifiers)) {
                self::fail('identifiers', 'IDENTIFIERS_INVALID');
            }

            return [$units->countBy('lot_id')->all(), $units->pluck('id')->all()];
        }
        $lots = [];
        $left = $quantity;
        foreach (
            DB::table('order_preparation_picks')->where('order_line_id', $line->id)->orderBy('picked_at')->get() as $pick
        ) {
            foreach (json_decode($pick->sources, true) as $source) {
                $take = min($left, (int) $source['quantity']);
                if ($take > 0) {
                    $lots[$source['lot_id']] = ($lots[$source['lot_id']] ?? 0) + $take;
                    $left -= $take;
                }
            }
        }

        return [$lots, []];
    }

    /** Control before any resale: resellable units become available, defective ones are set aside as damaged. */
    public function control(string $returnId, array $data, User $actor): array
    {
        $initial = Records::row('after_sales_returns', $returnId, false);
        $case = Records::row('after_sales_cases', $initial->case_id);
        $return = Records::row('after_sales_returns', $returnId);
        abort_unless((int) $return->version === $data['version'], 409);
        if ($return->control !== 'pending') {
            self::fail('control', 'CASE_STATE');
        }
        // A unit sent for repair stays the customer's: it follows the repair, never a resale control.
        if ($case->resolution === 'repair') {
            self::fail('control', 'REPAIR_USES_REPAIR_FLOW');
        }
        $balance = Records::row('inventory_balances', $return->balance_id, false);
        app(Inventory::class)->move(
            [
                'balance_id' => $balance->id,
                'location_id' => $data['location_id'] ?? $balance->location_id,
                'condition' => $data['result'] === 'resellable' ? 'available' : 'damaged',
                'quantity' => (int) $return->quantity,
                'unit_ids' => DB::table('inventory_units')
                    ->where('balance_id', $balance->id)
                    ->whereIn('identifier', json_decode($return->identifiers, true))
                    ->pluck('id')
                    ->all(),
                'reason' => 'After-sales control '.$case->number,
                'document_id' => null,
            ],
            $return->id,
            $actor,
        );
        DB::table('after_sales_returns')
            ->where('id', $return->id)
            ->update([
                'control' => $data['result'],
                'control_note' => $data['note'],
                'controlled_by' => $actor->id,
                'controlled_at' => now(),
                'version' => $return->version + 1,
            ]);
        $this->update($case, []);
        $this->event($case->id, $actor, 'return_controlled', ['return_id' => $return->id, 'result' => $data['result']]);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    /** Closed only once the remedy really happened: refund paid, returned units received and controlled. */
    public function resolve(string $id, array $data, User $actor): array
    {
        $case = $this->lock($id, $data['version'], ['accepted']);
        if (in_array($case->resolution, ['return_refund', 'refund'], true)) {
            $status = $case->refund_id
                ? DB::table('customer_refunds')->where('id', $case->refund_id)->value('status')
                : null;
            if ($status !== 'paid') {
                self::fail('case', 'REFUND_NOT_PAID');
            }
        }
        // An exchange is done once the replacement item really reached the customer.
        if ($case->resolution === 'exchange' && ! app(AfterSalesTrips::class)->exchangeDelivered($case->id)) {
            self::fail('case', 'EXCHANGE_NOT_DELIVERED');
        }
        // A repair is done once every repaired unit is back with the customer.
        if ($case->resolution === 'repair' && ! app(AfterSalesRepairs::class)->complete($case->id)) {
            self::fail('case', 'REPAIR_NOT_RETURNED');
        }
        if (in_array($case->resolution, ['return_refund', 'exchange', 'repair'], true)) {
            if ($this->returnedQuantity($case->id) < $case->quantity) {
                self::fail('case', 'RETURN_REQUIRED');
            }
            if (
                $case->resolution !== 'repair' &&
                DB::table('after_sales_returns')->where('case_id', $case->id)->where('control', 'pending')->exists()
            ) {
                self::fail('case', 'CONTROL_REQUIRED');
            }
        }
        $this->update($case, ['status' => 'resolved', 'closed_at' => now()]);
        $this->post($case, $actor, 'customer', $data['message'], null);
        $this->event($case->id, $actor, 'resolved', []);

        return $this->staffView(Records::row('after_sales_cases', $case->id, false));
    }

    private function returnedQuantity(string $caseId): int
    {
        return (int) DB::table('after_sales_returns')->where('case_id', $caseId)->sum('quantity');
    }

    private function lock(string $id, int $version, array $statuses): object
    {
        $case = Records::row('after_sales_cases', $id);
        abort_unless((int) $case->version === $version, 409);
        if (! in_array($case->status, $statuses, true)) {
            self::fail('case', 'CASE_STATE');
        }

        return $case;
    }

    private function update(object $case, array $changes): void
    {
        DB::table('after_sales_cases')
            ->where('id', $case->id)
            ->update([...$changes, 'version' => $case->version + 1, 'updated_at' => now()]);
    }

    private function post(object $case, User $author, string $visibility, ?string $body, ?string $documentId): void
    {
        DB::table('after_sales_messages')->insert([
            'id' => Records::id(),
            'case_id' => $case->id,
            'author_id' => $author->id,
            'visibility' => $visibility,
            'body' => $body,
            'document_id' => $documentId,
            'created_at' => now(),
        ]);
    }

    private function event(string $caseId, User $actor, string $action, array $data): void
    {
        Records::audit($actor, 'after_sales.'.$action, $caseId, $data);
    }

    private function messages(object $case, bool $internal): array
    {
        return DB::table('after_sales_messages AS m')
            ->join('users AS u', 'u.id', '=', 'm.author_id')
            ->where('m.case_id', $case->id)
            ->when(! $internal, fn ($q) => $q->where('m.visibility', 'customer'))
            ->orderBy('m.created_at')
            ->orderBy('m.id')
            ->get(['m.*', 'u.name'])
            ->map(
                fn ($m) => [
                    'id' => $m->id,
                    'from' => (int) $m->author_id === (int) $case->user_id ? 'customer' : 'kora',
                    // Customers see a first name for KORA's agents, never their account details.
                    'author' => (int) $m->author_id === (int) $case->user_id ? null : strtok((string) $m->name, ' '),
                    'visibility' => $m->visibility,
                    'body' => $m->body,
                    'attachment' => $m->document_id !== null,
                    'created_at' => Records::iso($m->created_at),
                    ...$internal ? ['author_id' => (int) $m->author_id, 'document_id' => $m->document_id] : [],
                ],
            )
            ->all();
    }

    public function customerView(object $case): array
    {
        $locale = app()->getLocale();
        $line = DB::table('order_lines')->where('id', $case->order_line_id)->first();
        $snapshot = json_decode($line->snapshot, true);
        $assignee = $case->assignee_id ? DB::table('users')->where('id', $case->assignee_id)->value('name') : null;

        return [
            'id' => $case->id,
            'number' => $case->number,
            'status' => $case->status,
            'version' => (int) $case->version,
            'order_id' => $case->order_id,
            'order_line_id' => $case->order_line_id,
            'product' => $snapshot['name'][$locale] ?? $snapshot['name']['fr'],
            'kind' => $case->kind,
            'quantity' => (int) $case->quantity,
            'identifiers' => json_decode($case->identifiers, true),
            'description' => $case->description,
            'contact' => $assignee ? strtok($assignee, ' ') : null,
            'proposal' => $case->resolution
                ? [
                    'resolution' => $case->resolution,
                    'amount' => $case->resolution_amount === null ? null : (string) $case->resolution_amount,
                ]
                : null,
            'refund_status' => $case->refund_id
                ? DB::table('customer_refunds')->where('id', $case->refund_id)->value('status')
                : null,
            'messages' => $this->messages($case, false),
            // Courier trips for this case: who comes, and the code for an exchange handover.
            'trips' => DB::table('after_sales_trips')
                ->where('case_id', $case->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($t) => app(AfterSalesTrips::class)->customerView($t))
                ->values(),
            'repairs' => DB::table('after_sales_repairs')
                ->where('case_id', $case->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => app(AfterSalesRepairs::class)->customerDto($r))
                ->values(),
            'created_at' => Records::iso($case->created_at),
            'closed_at' => Records::iso($case->closed_at),
        ];
    }

    public function staffView(object $case): array
    {
        $order = DB::table('orders')
            ->where('id', $case->order_id)
            ->first(['number', 'delivered_at']);

        return [
            ...$this->customerView($case),
            'order_number' => $order->number,
            'delivered_at' => Records::iso($order->delivered_at),
            'reported_quickly' => (bool) $case->reported_quickly,
            'customer_id' => (int) $case->user_id,
            'assignee_id' => $case->assignee_id === null ? null : (int) $case->assignee_id,
            'responsibility' => $case->responsibility,
            'responsibility_note' => $case->responsibility_note,
            'refund_id' => $case->refund_id,
            'returns' => DB::table('after_sales_returns')
                ->where('case_id', $case->id)
                ->orderBy('received_at')
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($r) => [
                        'id' => $r->id,
                        'balance_id' => $r->balance_id,
                        'quantity' => (int) $r->quantity,
                        'identifiers' => json_decode($r->identifiers, true),
                        'control' => $r->control,
                        'control_note' => $r->control_note,
                        'version' => (int) $r->version,
                        'received_at' => Records::iso($r->received_at),
                        'controlled_at' => Records::iso($r->controlled_at),
                    ],
                )
                ->values(),
            'repairs' => DB::table('after_sales_repairs')
                ->where('case_id', $case->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => app(AfterSalesRepairs::class)->dto($r))
                ->values(),
            'supplier_returns' => DB::table('after_sales_supplier_returns')
                ->where('case_id', $case->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => app(AfterSalesRepairs::class)->supplierReturnDto($r))
                ->values(),
            'messages' => $this->messages($case, true),
        ];
    }
}
