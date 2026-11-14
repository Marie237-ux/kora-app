<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Contracts;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplyContractController extends OperationalController
{
    public function index(Request $request, Contracts $contracts)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:draft,reviewed,approved,accepted,terminated'],
        ]);
        $query = DB::table('supply_contracts')->orderByDesc('created_at')->orderBy('id');
        foreach (['supplier_id', 'status'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return $this->page($query, $request, fn ($row) => $contracts->dto($row));
    }

    public function show(string $id, Contracts $contracts)
    {
        return ['data' => $contracts->dto(Records::row('supply_contracts', $id, false))];
    }

    public function store(Request $request, Contracts $contracts)
    {
        $input = $contracts->validate($request->all());

        return $this->command(
            $request,
            'contract.create',
            $input,
            fn ($data) => $contracts->create($data, $request->user()),
            201,
        );
    }

    public function update(Request $request, string $id, Contracts $contracts)
    {
        $input = $contracts->validate($request->all());
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];

        return $this->command(
            $request,
            'contract.update:'.$id,
            [...$input, 'version' => $version],
            fn ($data) => $contracts->update($id, $data, $version, $request->user()),
        );
    }

    public function transition(Request $request, string $id, string $action, Contracts $contracts)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'signed_document_id' => ['nullable', 'uuid'],
            'signer_name' => ['nullable', 'string', 'max:150'],
            'method' => ['nullable', 'in:signed_copy,recorded_acceptance'],
            'reference' => ['nullable', 'string', 'max:200'],
            'accepted_at' => ['nullable', 'date', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'contract.'.$action.':'.$id,
            $data,
            fn ($input) => $contracts->transition($id, $input['version'], $action, $input, $request->user()),
        );
    }

    public function revisions(Request $request, string $id)
    {
        Records::row('supply_contracts', $id, false);

        return $this->page(
            DB::table('supply_contract_revisions')->where('contract_id', $id)->orderByDesc('version'),
            $request,
            fn ($r) => [
                'version' => (int) $r->version,
                'action' => $r->action,
                'snapshot' => json_decode($r->snapshot, true),
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }
}
