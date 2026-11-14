<?php

namespace App\Http\Controllers;

use App\Domain\Shared\BusinessEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgreementController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['supplier_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table('purchase_agreements')->orderByDesc('starts_at')->orderBy('id');
        if (! empty($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
        }
        $page = $query->paginate(24);

        return [
            'data' => collect($page->items())->map(fn ($r) => $this->dto($r)),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('status', 'active')],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'minimum_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'priority' => ['required', 'integer', 'min:0', 'max:100'],
            'mode' => ['required', 'in:discount,fixed'],
            'discount_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'fixed_cost' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'starts_at' => ['required', 'date', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'ends_at' => ['nullable', 'date', 'after:starts_at', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'contract_reference' => ['required', 'string', 'max:150'],
        ]);
        if (
            (! empty($data['category_id']) && ! empty($data['product_id'])) ||
            ($data['mode'] === 'fixed' && ! isset($data['fixed_cost'])) ||
            ($data['mode'] === 'discount' && isset($data['fixed_cost']))
        ) {
            throw ValidationException::withMessages(['agreement' => __('catalogue.AGREEMENT_INVALID')]);
        }
        $data['category_id'] ??= null;
        $data['product_id'] ??= null;
        $data['fixed_cost'] ??= null;
        if ($data['mode'] === 'fixed') {
            $data['discount_basis_points'] = 0;
        }
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc()->format('Y-m-d H:i:s');
        $data['ends_at'] = ! empty($data['ends_at'])
            ? CarbonImmutable::parse($data['ends_at'])->utc()->format('Y-m-d H:i:s')
            : null;
        $record = DB::transaction(function () use ($data, $request) {
            $supplier = DB::table('suppliers')->where('id', $data['supplier_id'])->lockForUpdate()->first();
            if (! $supplier || $supplier->status !== 'active') {
                throw ValidationException::withMessages(['supplier_id' => __('catalogue.SUPPLIER_INACTIVE')]);
            }
            $overlap = DB::table('purchase_agreements')
                ->where('supplier_id', $data['supplier_id'])
                ->whereNull('withdrawn_at')
                ->where('category_id', $data['category_id'])
                ->where('product_id', $data['product_id'])
                ->where('minimum_quantity', $data['minimum_quantity'])
                ->where('priority', $data['priority'])
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $data['starts_at']));
            if ($data['ends_at']) {
                $overlap->where('starts_at', '<', $data['ends_at']);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['agreement' => __('catalogue.AGREEMENT_OVERLAP')]);
            }
            $id = (string) Str::uuid();
            DB::table('purchase_agreements')->insert([
                ...$data,
                'id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(BusinessEvents::class)->audit($request->user(), 'agreement.created', $id, [
                'supplier_id' => $data['supplier_id'],
            ]);

            return DB::table('purchase_agreements')->find($id);
        }, 3);

        return response()->json(['data' => $this->dto($record)], 201);
    }

    public function withdraw(Request $request, string $id)
    {
        $record = DB::transaction(function () use ($id, $request) {
            $record = DB::table('purchase_agreements')->find($id);
            abort_unless($record, 404);
            DB::table('suppliers')->where('id', $record->supplier_id)->lockForUpdate()->first();
            $record = DB::table('purchase_agreements')->where('id', $id)->lockForUpdate()->first();
            if (! $record->withdrawn_at) {
                DB::table('purchase_agreements')
                    ->where('id', $id)
                    ->update(['withdrawn_at' => now(), 'updated_at' => now()]);
                app(BusinessEvents::class)->audit($request->user(), 'agreement.withdrawn', $id, [
                    'supplier_id' => $record->supplier_id,
                ]);
            }

            return DB::table('purchase_agreements')->find($id);
        }, 3);

        return ['data' => $this->dto($record)];
    }

    private function dto(object $record): array
    {
        $data = (array) $record;
        foreach (['starts_at', 'ends_at', 'withdrawn_at', 'created_at', 'updated_at'] as $key) {
            $data[$key] = $data[$key] ? CarbonImmutable::parse($data[$key], 'UTC')->toIso8601String() : null;
        }
        foreach (['minimum_quantity', 'priority', 'discount_basis_points', 'fixed_cost'] as $key) {
            $data[$key] = $data[$key] === null ? null : (int) $data[$key];
        }

        return $data;
    }
}
