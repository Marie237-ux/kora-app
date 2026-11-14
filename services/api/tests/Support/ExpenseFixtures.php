<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait ExpenseFixtures
{
    protected function expenseData(array $c, array $changes = []): array
    {
        return [
            ...[
                'binding_id' => $c['binding']['id'],
                'amount' => '1000',
                'category' => 'operating_expense',
                'description' => ['fr' => 'Dépense fictive', 'en' => 'Fictional expense'],
                'payee' => 'Test only',
                'reference' => 'TEST-EXPENSE',
                'planned_on' => '2026-11-04',
                'due_on' => '2026-11-10',
                'document_id' => $c['evidence']['id'],
                'note' => 'Fictional test budget',
            ],
            ...$changes,
        ];
    }

    protected function expensePlan(array $c, array $changes = [], bool $approve = true): array
    {
        $p = $this->op('POST', $c['root'].'/expenses', $this->expenseData($c, $changes))
            ->assertCreated()
            ->json('data');
        if ($approve) {
            $this->operationUser('accountant');
            $p = $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/approve', [
                'version' => 1,
                'sha256' => $p['sha256'],
                'date' => '2026-11-04',
                'note' => 'Test review',
            ])
                ->assertOk()
                ->json('data');
        }

        return $p;
    }

    protected function expenseCash(array $c, string $amount = '400', string $date = '2026-11-04'): array
    {
        $d = $this->accountingEntryData($c, $amount, $date);
        if ($c['binding']['inflow_side'] === 'debit') {
            $d['lines'] = array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $d['lines']);
        }
        if ($c['book']['mode'] === 'live') {
            $d['document_ids'] = [$c['evidence']['id']];
        }
        $e = $this->op('POST', $c['root'].'/entries', $d)
            ->assertCreated()
            ->json('data');
        $e = $this->accountingPost($c, $e)->assertOk()->json('data');
        $e['line_id'] = (int) DB::table('accounting_entry_lines')
            ->where('entry_id', $e['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');

        return $e;
    }

    protected function expensePaymentData(array $c, array $e): array
    {
        return [
            'version' => 2,
            'binding_id' => $c['binding']['id'],
            'entry_id' => $e['id'],
            'ledger_line_id' => $e['line_id'],
            'accepted_amount' => $e['lines'][0][$c['binding']['inflow_side'] === 'debit' ? 'credit' : 'debit'],
            'document_id' => $c['evidence']['id'],
            'paid_at' => now()->subMinute()->toIso8601String(),
            'note' => 'Test manual payment evidence',
        ];
    }

    protected function expensePayment(array $c, array $p, array $e): array
    {
        return $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', $this->expensePaymentData($c, $e))
            ->assertCreated()
            ->json('data');
    }

    protected function expenseReversal(array $c, array $payment, string $date = '2026-11-05'): array
    {
        return [
            'version' => $payment['version'],
            'sha256' => $payment['sha256'],
            'journal_id' => $c['journal']['id'],
            'entry_date' => $date,
            'description' => ['fr' => 'Correction fictive', 'en' => 'Test correction'],
            'note' => 'Test exact reversal',
        ];
    }
}
