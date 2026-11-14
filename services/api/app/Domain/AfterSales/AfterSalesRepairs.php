<?php

namespace App\Domain\AfterSales;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own unit during a repair, and defective units sent back to their supplier.
 *
 * Repair: once the unit is received back (it waits in a non-sellable condition, never controlled as stock for
 * resale), a repair record follows it to the repairer and back, with the slips, the outcome and who bears the
 * cost; a courier then brings the same unit back to the customer with a one-time code. The case closes only
 * when the unit has reached the customer.
 *
 * Supplier return: a unit found defective after control leaves stock to its supplier through the existing
 * supplier return, linked to the case; the supplier's answer (credit, replacement, repair, refusal) is recorded
 * with its proof. A credit itself still goes through the payables flow, never assumed.
 */
final class AfterSalesRepairs
{
    public function open(string $caseId, array $data, User $actor): array
    {
        $case = Records::row('after_sales_cases', $caseId);
        if ($case->status !== 'accepted' || $case->resolution !== 'repair') {
            AfterSales::fail('case', 'CASE_STATE');
        }
        $return = DB::table('after_sales_returns')
            ->where('id', $data['return_id'])
            ->where('case_id', $case->id)
            ->first();
        abort_unless($return, 404);
        if (
            $data['repairer_kind'] === 'supplier' &&
            ! DB::table('suppliers')
                ->where('id', $data['supplier_id'] ?? null)
                ->exists()
        ) {
            AfterSales::fail('supplier_id', 'REPAIRER_REQUIRED');
        }
        $id = Records::id();
        DB::table('after_sales_repairs')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('after_sales_repair', 'REP-', 7),
            'case_id' => $case->id,
            'return_id' => $return->id,
            'balance_id' => $return->balance_id,
            'quantity' => $return->quantity,
            'identifiers' => $return->identifiers,
            'repairer_kind' => $data['repairer_kind'],
            'supplier_id' => $data['repairer_kind'] === 'supplier' ? $data['supplier_id'] : null,
            'repairer_name' => $data['repairer_name'] ?? null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'after_sales.repair_opened', $id, [
            'case_id' => $case->id,
            'repairer' => $data['repairer_kind'],
        ]);

        return $this->dto(Records::row('after_sales_repairs', $id, false));
    }

    /** Handed to the repairer with a slip; the unit moves to the location that stands for "at the repairer". */
    public function send(string $id, array $data, User $actor): array
    {
        $repair = $this->lock($id, $data['version'], ['open']);
        if ($repair->repairer_kind === 'internal') {
            AfterSales::fail('repair', 'REPAIR_STATE');
        }
        $document = $this->evidence($data['document_id'], $repair);
        $target = $this->move($repair, $data['location_id'], 'Repair sent '.$repair->number, $document->id, $actor);
        $this->update($repair, [
            'status' => 'at_repairer',
            'balance_id' => $target,
            'sent_at' => now(),
            'sent_document_id' => $document->id,
            'repairer_reference' => $data['reference'] ?? null,
        ]);
        Records::audit($actor, 'after_sales.repair_sent', $id, ['location_id' => $data['location_id']]);

        return $this->dto(Records::row('after_sales_repairs', $id, false));
    }

    /** Back at the depot (or done in-house), with its outcome, slip and who bears any cost. */
    public function back(string $id, array $data, User $actor): array
    {
        $repair = $this->lock($id, $data['version'], ['open', 'at_repairer']);
        if ($repair->status === 'open' && $repair->repairer_kind !== 'internal') {
            AfterSales::fail('repair', 'REPAIR_STATE');
        }
        $document = isset($data['document_id']) ? $this->evidence($data['document_id'], $repair) : null;
        if ($repair->status === 'at_repairer' && ! $document) {
            AfterSales::fail('document_id', 'REPAIR_SLIP_REQUIRED');
        }
        $balance =
            $repair->status === 'at_repairer'
                ? $this->move($repair, $data['location_id'], 'Repair back '.$repair->number, $document->id, $actor)
                : $repair->balance_id;
        $this->update($repair, [
            'status' => 'back',
            'balance_id' => $balance,
            'outcome' => $data['outcome'],
            'outcome_note' => $data['note'],
            'back_document_id' => $document?->id,
            'cost' => $data['cost'] ?? null,
            'cost_bearer' => $data['cost_bearer'] ?? null,
            'back_at' => now(),
        ]);
        Records::audit($actor, 'after_sales.repair_back', $id, [
            'outcome' => $data['outcome'],
            'cost' => $data['cost'] ?? null,
            'cost_bearer' => $data['cost_bearer'] ?? null,
        ]);

        return $this->dto(Records::row('after_sales_repairs', $id, false));
    }

    /** Called when the courier's delivery leaves with the unit: it leaves stock to its owner. */
    public function issue(object $repair, string $tripId, User $actor): void
    {
        if ($repair->status !== 'back') {
            AfterSales::fail('repair', 'REPAIR_STATE');
        }
        $case = Records::row('after_sales_cases', $repair->case_id, false);
        app(Inventory::class)->returnRepaired(
            $repair->balance_id,
            (int) $repair->quantity,
            json_decode($repair->identifiers, true),
            $tripId,
            'Repaired unit back to customer '.$case->number,
            $actor,
        );
        $this->update($repair, ['status' => 'returning']);
    }

    public function delivered(string $repairId): void
    {
        $repair = Records::row('after_sales_repairs', $repairId);
        if ($repair->status === 'returning') {
            $this->update($repair, ['status' => 'returned', 'returned_at' => now()]);
        }
    }

    /** All repairs of the case brought back to the customer. */
    public function complete(string $caseId): bool
    {
        $statuses = DB::table('after_sales_repairs')->where('case_id', $caseId)->pluck('status');

        return $statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === 'returned');
    }

    public function sendToSupplier(string $returnId, array $data, User $actor): array
    {
        $return = Records::row('after_sales_returns', $returnId, false);
        $case = Records::row('after_sales_cases', $return->case_id);
        if ($return->control !== 'defective') {
            AfterSales::fail('return', 'DEFECTIVE_ONLY');
        }
        $balance = Records::row('inventory_balances', $data['balance_id'], false);
        $origin = Records::row('inventory_balances', $return->balance_id, false);
        // The defective units of this return: same lot, set aside as damaged by the control.
        if ($balance->lot_id !== $origin->lot_id || $balance->condition !== 'damaged') {
            AfterSales::fail('balance_id', 'DEFECTIVE_ONLY');
        }
        $lot = Records::row('stock_lots', $balance->lot_id, false);
        app(PrivateDocuments::class)->requireClean($data['document_id'], $lot->supplier_id, ['stock_evidence']);
        $identifiers = json_decode($return->identifiers, true);
        $sent = app(Inventory::class)->returnToSupplier(
            [
                'balance_id' => $balance->id,
                'quantity' => $data['quantity'],
                'unit_ids' => $identifiers
                    ? DB::table('inventory_units')
                        ->where('balance_id', $balance->id)
                        ->whereIn('identifier', $identifiers)
                        ->limit($data['quantity'])
                        ->pluck('id')
                        ->all()
                    : [],
                'document_id' => $data['document_id'],
                'reason' => $data['reason'],
                'supplier_reference' => $data['supplier_reference'],
            ],
            $actor,
        );
        $id = Records::id();
        DB::table('after_sales_supplier_returns')->insert([
            'id' => $id,
            'case_id' => $case->id,
            'return_id' => $return->id,
            'supplier_return_id' => $sent['id'],
            'supplier_id' => $lot->supplier_id,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'after_sales.sent_to_supplier', $id, [
            'case_id' => $case->id,
            'quantity' => $data['quantity'],
        ]);

        return $this->supplierReturnDto(Records::row('after_sales_supplier_returns', $id, false));
    }

    public function supplierDecision(string $id, array $data, User $actor): array
    {
        $row = Records::row('after_sales_supplier_returns', $id);
        abort_unless((int) $row->version === $data['version'], 409);
        if ($row->decision !== 'pending') {
            AfterSales::fail('decision', 'DECISION_RECORDED');
        }
        $document = isset($data['document_id'])
            ? app(PrivateDocuments::class)->requireClean($data['document_id'], $row->supplier_id, [
                'stock_evidence',
                'supplier_contract',
                'supplier_invoice',
                'supplier_portal',
            ])
            : null;
        DB::table('after_sales_supplier_returns')
            ->where('id', $id)
            ->update([
                'decision' => $data['decision'],
                'decision_note' => $data['note'],
                'decision_document_id' => $document?->id,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'version' => $row->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'after_sales.supplier_decision', $id, ['decision' => $data['decision']]);

        return $this->supplierReturnDto(Records::row('after_sales_supplier_returns', $id, false));
    }

    private function evidence(string $documentId, object $repair): object
    {
        $supplier =
            $repair->supplier_id ??
            DB::table('stock_lots')
                ->where('id', DB::table('inventory_balances')->where('id', $repair->balance_id)->value('lot_id'))
                ->value('supplier_id');

        return app(PrivateDocuments::class)->requireClean($documentId, $supplier, ['stock_evidence']);
    }

    private function move(object $repair, string $locationId, string $reason, string $documentId, User $actor): string
    {
        $balance = Records::row('inventory_balances', $repair->balance_id, false);
        $identifiers = json_decode($repair->identifiers, true);
        $moved = app(Inventory::class)->move(
            [
                'balance_id' => $balance->id,
                'location_id' => $locationId,
                'condition' => $balance->condition,
                'quantity' => (int) $repair->quantity,
                'unit_ids' => $identifiers
                    ? DB::table('inventory_units')
                        ->where('balance_id', $balance->id)
                        ->whereIn('identifier', $identifiers)
                        ->pluck('id')
                        ->all()
                    : [],
                'reason' => $reason,
                'document_id' => $documentId,
            ],
            $repair->id,
            $actor,
        );

        return $moved['target']['id'];
    }

    private function lock(string $id, int $version, array $statuses): object
    {
        $initial = Records::row('after_sales_repairs', $id, false);
        Records::row('after_sales_cases', $initial->case_id);
        $repair = Records::row('after_sales_repairs', $id);
        abort_unless((int) $repair->version === $version, 409);
        if (! in_array($repair->status, $statuses, true)) {
            AfterSales::fail('repair', 'REPAIR_STATE');
        }

        return $repair;
    }

    private function update(object $repair, array $changes): void
    {
        DB::table('after_sales_repairs')
            ->where('id', $repair->id)
            ->update([...$changes, 'version' => $repair->version + 1, 'updated_at' => now()]);
    }

    public function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'case_id' => $r->case_id,
            'return_id' => $r->return_id,
            'quantity' => (int) $r->quantity,
            'identifiers' => json_decode($r->identifiers, true),
            'repairer_kind' => $r->repairer_kind,
            'supplier_id' => $r->supplier_id,
            'repairer_name' => $r->repairer_name,
            'status' => $r->status,
            'outcome' => $r->outcome,
            'outcome_note' => $r->outcome_note,
            'repairer_reference' => $r->repairer_reference,
            'cost' => $r->cost === null ? null : (string) $r->cost,
            'cost_bearer' => $r->cost_bearer,
            'sent_at' => Records::iso($r->sent_at),
            'back_at' => Records::iso($r->back_at),
            'returned_at' => Records::iso($r->returned_at),
            'version' => (int) $r->version,
        ];
    }

    /** What the customer sees: where the repair stands and its outcome; no repairer, cost or slips. */
    public function customerDto(object $r): array
    {
        return [
            'number' => $r->number,
            'status' => $r->status,
            'outcome' => $r->outcome,
            'returned_at' => Records::iso($r->returned_at),
        ];
    }

    public function supplierReturnDto(object $r): array
    {
        $sent = DB::table('supplier_returns')->where('id', $r->supplier_return_id)->first();

        return [
            'id' => $r->id,
            'case_id' => $r->case_id,
            'return_id' => $r->return_id,
            'supplier_return_id' => $r->supplier_return_id,
            'supplier_id' => $r->supplier_id,
            'quantity' => (int) $sent->quantity,
            'supplier_reference' => $sent->supplier_reference,
            'decision' => $r->decision,
            'decision_note' => $r->decision_note,
            'decided_at' => Records::iso($r->decided_at),
            // A credit, when the supplier grants one, is recorded in payables against this supplier return.
            'credit' => ($credit = DB::table('supplier_credits')->where('return_id', $r->supplier_return_id)->first())
                ? ['id' => $credit->id, 'amount' => (string) $credit->amount, 'reference' => $credit->reference]
                : null,
            'version' => (int) $r->version,
            'created_at' => Records::iso($r->created_at),
        ];
    }
}
