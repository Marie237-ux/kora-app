<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\PayableProjection;
use App\Domain\Procurement\Payables;
use App\Domain\Procurement\Records;
use App\Domain\Procurement\SupplierLedger;
use App\Domain\Shared\ReadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PayablesController extends OperationalController
{
    public function accounts(Request $request)
    {
        return $this->page(
            DB::table('treasury_accounts')->orderBy('code'),
            $request,
            fn ($r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'kind' => $r->kind,
                'active' => (bool) $r->active,
            ],
        );
    }

    public function createAccount(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:bank,mobile_money,monetbil,cash'],
        ]);

        return $this->command(
            $request,
            'treasury_account.create',
            $data,
            function ($d) use ($request) {
                $id = Records::id();
                DB::table('treasury_accounts')->insert([
                    'id' => $id,
                    ...$d,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Records::audit($request->user(), 'treasury_account.created', $id);

                return ['id' => $id, ...$d, 'active' => true];
            },
            201,
        );
    }

    public function obligations(Request $request, Payables $payables)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'purchase_order_id' => ['nullable', 'uuid'],
        ]);
        $q = DB::table('supplier_obligations')->orderByDesc('created_at')->orderBy('id');
        foreach (['supplier_id', 'purchase_order_id'] as $f) {
            if (! empty($data[$f])) {
                $q->where($f, $data[$f]);
            }
        }

        return $this->page($q, $request, fn ($r) => $payables->obligationDto($r));
    }

    public function schedule(Request $request, Payables $payables)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:pending,past_due,settled'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($data, $request) {
            $q = app(PayableProjection::class)
                ->query($data['supplier_id'] ?? null, $data['contract_id'] ?? null)
                ->orderBy('due_on')
                ->orderBy('id');
            if (! empty($data['from'])) {
                $q->where('due_on', '>=', $data['from']);
            }
            if (! empty($data['to'])) {
                $q->where('due_on', '<=', $data['to']);
            }
            $today = now('Africa/Douala')->toDateString();
            if (($data['status'] ?? null) === 'settled') {
                $q->where('outstanding', 0);
            }
            if (in_array($data['status'] ?? null, ['pending', 'past_due'], true)) {
                $q->where('outstanding', '>', 0)->where('due_on', $data['status'] === 'past_due' ? '<' : '>=', $today);
            }

            return $this->page(
                $q,
                $request,
                fn ($r) => [
                    'id' => $r->id,
                    'obligation_id' => $r->obligation_id,
                    'supplier_id' => $r->supplier_id,
                    'amount' => (string) $r->amount,
                    'settled' => (string) ($r->amount - $r->outstanding),
                    'outstanding' => (string) $r->outstanding,
                    'due_on' => $r->due_on,
                    'sequence' => (int) $r->sequence,
                    'version' => (int) $r->version,
                    'status' => $r->outstanding == 0 ? 'settled' : ($r->due_on < $today ? 'past_due' : 'pending'),
                ],
            );
        });
    }

    public function reschedule(Request $request, string $id, Payables $payables)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'document_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
            'installments' => ['required', 'array', 'min:1', 'max:24'],
            'installments.*' => ['array:amount,due_on'],
            'installments.*.amount' => ['required', 'string'],
            'installments.*.due_on' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->command(
            $request,
            'payable.reschedule:'.$id,
            $data,
            fn ($d) => $payables->reschedule(Records::row('supplier_obligations', $id, false), $d, $request->user()),
        );
    }

    public function payments(Request $request, Payables $payables)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:requested,verified'],
        ]);
        $q = DB::table('supplier_payments')->orderByDesc('created_at')->orderBy('id');
        foreach (['supplier_id', 'status'] as $f) {
            if (! empty($data[$f])) {
                $q->where($f, $data[$f]);
            }
        }

        return $this->page($q, $request, fn ($r) => $payables->paymentDto($r));
    }

    public function requestPayment(Request $request, Payables $payables)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:150'],
        ]);

        return $this->command(
            $request,
            'supplier_payment.request',
            $data,
            fn ($d) => $payables->requestPayment($d, $request->user()),
            201,
        );
    }

    public function verifyPayment(Request $request, string $id, Payables $payables)
    {
        $data = $request->validate([
            ...$this->allocationRules(),
            'evidence_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:150'],
            'paid_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'allow_advance' => ['required', 'boolean'],
        ]);

        return $this->command(
            $request,
            'supplier_payment.verify:'.$id,
            $data,
            fn ($d) => $payables->verifyPayment($id, $d, $request->user()),
        );
    }

    public function allocateAdvance(Request $request, string $id, Payables $payables)
    {
        $data = $request->validate($this->allocationRules());

        return $this->command(
            $request,
            'supplier_payment.allocate:'.$id,
            $data,
            fn ($d) => $payables->allocateAdvance($id, $d['allocations'], $request->user()),
        );
    }

    public function credit(Request $request, Payables $payables)
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'uuid'],
            'return_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'string'],
            'document_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'supplier_credit.approve',
            $data,
            fn ($d) => $payables->credit($d, $request->user()),
            201,
        );
    }

    public function credits(Request $request)
    {
        $data = $request->validate(['supplier_id' => ['nullable', 'uuid'], 'invoice_id' => ['nullable', 'uuid']]);
        $q = DB::table('supplier_credits')->orderByDesc('created_at')->orderBy('id');
        foreach (['supplier_id', 'invoice_id'] as $f) {
            if (! empty($data[$f])) {
                $q->where($f, $data[$f]);
            }
        }

        return $this->page($q, $request, function ($r) {
            $applied = (int) DB::table('supplier_credit_allocations')->where('credit_id', $r->id)->sum('amount');

            return [
                'id' => $r->id,
                'supplier_id' => $r->supplier_id,
                'invoice_id' => $r->invoice_id,
                'return_id' => $r->return_id,
                'reference' => $r->reference,
                'amount' => (string) $r->amount,
                'applied' => (string) $applied,
                'unapplied' => (string) ($r->amount - $applied),
                'reason' => $r->reason,
                'document_id' => $r->document_id,
                'created_at' => Records::iso($r->created_at),
            ];
        });
    }

    public function allocateCredit(Request $request, string $id, Payables $payables)
    {
        $data = $request->validate($this->allocationRules());

        return $this->command(
            $request,
            'supplier_credit.allocate:'.$id,
            $data,
            fn ($d) => $payables->allocateCredit($id, $d['allocations'], $request->user()),
        );
    }

    public function statement(Request $request, string $id, Payables $payables)
    {
        Records::row('suppliers', $id, false);
        $data = $request->validate([
            'as_of' => ['nullable', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);
        $at = ! empty($data['as_of']) ? CarbonImmutable::parse($data['as_of'])->utc() : CarbonImmutable::now('UTC');
        $locale = app()->getLocale();
        $copy =
            $locale === 'fr'
                ? [
                    'title' => 'Relevé fournisseur',
                    'balance' => 'Reliquat à régler',
                    'paid' => 'Règlements vérifiés',
                    'advance' => 'Avances à affecter',
                ]
                : [
                    'title' => 'Supplier statement',
                    'balance' => 'Outstanding payable',
                    'paid' => 'Verified payments',
                    'advance' => 'Unallocated advances',
                ];

        return DB::transaction(function () use ($id, $locale, $copy, $payables, $at) {
            Records::supplier($id);

            return [
                'data' => [
                    'supplier_id' => $id,
                    'locale' => $locale,
                    'labels' => $copy,
                    'balances' => $payables->balances($id, $at),
                    'details_url' => '/api/v1/operations/payables/obligations?supplier_id='.$id,
                    'ledger_url' => '/api/v1/operations/suppliers/'.$id.'/ledger?as_of='.urlencode($at->toIso8601String()),
                ],
            ];
        }, 3);
    }

    public function ledger(Request $request, string $id, SupplierLedger $ledger)
    {
        Records::row('suppliers', $id, false);
        $data = $request->validate([
            'as_of' => ['nullable', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);
        $at = ! empty($data['as_of']) ? CarbonImmutable::parse($data['as_of'])->utc() : CarbonImmutable::now('UTC');

        return [
            ...$this->page($ledger->query($id, $at), $request, fn ($r) => $ledger->dto($r)),
            'known_as_of' => $at->toIso8601String(),
        ];
    }
}
