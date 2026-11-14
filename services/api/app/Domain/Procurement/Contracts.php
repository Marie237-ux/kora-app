<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Contracts
{
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'reference' => ['required', 'string', 'max:150'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'text' => ['required', 'array:fr,en'],
            'text.fr' => ['required', 'string', 'max:50000'],
            'text.en' => ['required', 'string', 'max:50000'],
            'terms' => ['required', 'array:mode,due_basis,fixed_due_on,schedule,credit_limit,consignment_basis'],
            'terms.mode' => ['required', 'in:cash,credit,consignment'],
            'terms.due_basis' => ['required', 'in:invoice_date,first_receipt,fixed_date'],
            'terms.fixed_due_on' => ['nullable', 'date_format:Y-m-d'],
            'terms.credit_limit' => ['nullable', 'string'],
            'terms.consignment_basis' => ['nullable', 'in:approved_statement'],
            'terms.schedule' => ['required', 'array', 'min:1', 'max:24'],
            'terms.schedule.*' => ['required', 'array:basis_points,offset_days'],
            'terms.schedule.*.basis_points' => ['required', 'integer', 'min:1', 'max:10000'],
            'terms.schedule.*.offset_days' => ['required', 'integer', 'min:0', 'max:3660'],
        ])->validate();
        $terms = &$data['terms'];
        $terms['fixed_due_on'] ??= null;
        $terms['credit_limit'] ??= null;
        $terms['consignment_basis'] ??= null;
        if ($terms['credit_limit'] !== null) {
            $terms['credit_limit'] = (string) Money::parse($terms['credit_limit']);
        }
        if (
            array_sum(array_column($terms['schedule'], 'basis_points')) !== 10000 ||
            ($terms['due_basis'] === 'fixed_date' && ! $terms['fixed_due_on']) ||
            ($terms['due_basis'] !== 'fixed_date' && $terms['fixed_due_on']) ||
            ($terms['mode'] !== 'credit' && $terms['credit_limit'] !== null) ||
            ($terms['mode'] === 'consignment' && $terms['consignment_basis'] !== 'approved_statement') ||
            ($terms['mode'] !== 'consignment' && $terms['consignment_basis'] !== null)
        ) {
            Money::fail('terms', 'SCHEDULE_INVALID');
        }
        if (
            $terms['mode'] === 'cash' &&
            ($terms['due_basis'] !== 'invoice_date' ||
                count($terms['schedule']) !== 1 ||
                $terms['schedule'][0]['offset_days'] !== 0)
        ) {
            Money::fail('terms', 'SCHEDULE_INVALID');
        }
        usort($terms['schedule'], fn ($a, $b) => $a['offset_days'] <=> $b['offset_days']);

        return $data;
    }

    public function create(array $input, User $actor): array
    {
        $data = $this->validate($input);
        $supplier = Records::supplier($data['supplier_id']);
        if ($supplier->status !== 'active') {
            Money::fail('supplier_id', 'SUPPLIER_INACTIVE');
        }
        $id = Records::id();
        DB::table('supply_contracts')->insert([
            'id' => $id,
            'supplier_id' => $data['supplier_id'],
            'reference' => $data['reference'],
            'terms' => Records::json($data['terms']),
            'text' => Records::json($data['text']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->revision($id, 'created', $actor);

        return $this->dto(Records::row('supply_contracts', $id));
    }

    public function update(string $id, array $input, int $version, User $actor): array
    {
        $row = Records::row('supply_contracts', $id);
        abort_unless((int) $row->version === $version, 409);
        if ($row->status !== 'draft') {
            Money::fail('status', 'CONTRACT_LOCKED');
        }
        $data = $this->validate($input);
        if ($data['supplier_id'] !== $row->supplier_id) {
            Money::fail('supplier_id', 'CONTRACT_LOCKED');
        }
        DB::table('supply_contracts')
            ->where('id', $id)
            ->update([
                'reference' => $data['reference'],
                'terms' => Records::json($data['terms']),
                'text' => Records::json($data['text']),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
        $this->revision($id, 'updated', $actor);

        return $this->dto(Records::row('supply_contracts', $id));
    }

    public function transition(string $id, int $version, string $action, array $input, User $actor): array
    {
        $row = Records::row('supply_contracts', $id);
        abort_unless((int) $row->version === $version, 409);
        $expected = ['review' => 'draft', 'approve' => 'reviewed', 'accept' => 'approved', 'terminate' => 'accepted'];
        if (! isset($expected[$action]) || $row->status !== $expected[$action]) {
            Money::fail('status', 'CONTRACT_TRANSITION');
        }
        if (in_array($action, ['approve', 'terminate'], true)) {
            abort_unless(in_array('contracts.approve', $actor->permissions(), true), 403);
        }
        $values = [
            'status' => [
                'review' => 'reviewed',
                'approve' => 'approved',
                'accept' => 'accepted',
                'terminate' => 'terminated',
            ][$action],
            'version' => $version + 1,
            'updated_at' => now(),
        ];
        if ($action === 'approve') {
            $values['approved_by'] = $actor->id;
        }
        if ($action === 'accept') {
            $data = Validator::make($input, [
                'signed_document_id' => ['required', 'uuid'],
                'signer_name' => ['required', 'string', 'max:150'],
                'method' => ['required', 'in:signed_copy,recorded_acceptance'],
                'reference' => ['required', 'string', 'max:200'],
                'accepted_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            ])->validate();
            $doc = app(PrivateDocuments::class)->requireClean($data['signed_document_id'], $row->supplier_id, [
                'supplier_contract',
            ]);
            $values += [
                'signed_document_id' => $doc->id,
                'acceptance' => Records::json([
                    ...$data,
                    'document_sha256' => $doc->sha256,
                    'recorded_by' => $actor->id,
                ]),
            ];
        }
        if ($action === 'terminate') {
            Validator::make($input, ['reason' => ['required', 'string', 'max:1000']])->validate();
        }
        DB::table('supply_contracts')->where('id', $id)->update($values);
        $this->revision($id, $action, $actor, $input['reason'] ?? null);

        return $this->dto(Records::row('supply_contracts', $id));
    }

    public function effective(string $id, string $supplierId): object
    {
        $row = Records::row('supply_contracts', $id);
        $today = CarbonImmutable::now('Africa/Douala')->toDateString();
        if (
            $row->supplier_id !== $supplierId ||
            $row->status !== 'accepted' ||
            $row->starts_on > $today ||
            ($row->ends_on && $row->ends_on < $today)
        ) {
            Money::fail('contract_id', 'CONTRACT_NOT_EFFECTIVE');
        }

        return $row;
    }

    public function dto(object $row): array
    {
        return [
            'id' => $row->id,
            'supplier_id' => $row->supplier_id,
            'reference' => $row->reference,
            'terms' => json_decode($row->terms, true),
            'text' => json_decode($row->text, true),
            'status' => $row->status,
            'starts_on' => $row->starts_on,
            'ends_on' => $row->ends_on,
            'version' => (int) $row->version,
            'signed_document_id' => $row->signed_document_id,
            'acceptance' => $row->acceptance ? json_decode($row->acceptance, true) : null,
            'approved_by' => $row->approved_by,
            'created_at' => Records::iso($row->created_at),
            'updated_at' => Records::iso($row->updated_at),
        ];
    }

    private function revision(string $id, string $action, User $actor, ?string $reason = null): void
    {
        $row = Records::row('supply_contracts', $id);
        DB::table('supply_contract_revisions')->insert([
            'contract_id' => $id,
            'version' => $row->version,
            'action' => $action,
            'snapshot' => Records::json(['contract' => $this->dto($row), 'reason' => $reason]),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'contract.'.$action, $id, ['version' => $row->version]);
    }
}
