<?php

namespace Tests\Support;

use App\Domain\Documents\BillingIdentity;
use Illuminate\Support\Facades\DB;

trait WithdrawalFixtures
{
    protected function withdrawalContext(string $mode = 'demo', string $inflow = 'debit'): array
    {
        if ($mode === 'live') {
            app(BillingIdentity::class)->save(
                [
                    'legal_name' => 'Fictional withdrawal test operator',
                    'niu' => 'TEST-NIU',
                    'rccm' => 'TEST-RCCM',
                    'address' => 'Test',
                    'city' => 'Test',
                    'tax_regime' => 'exempt',
                    'mentions' => ['fr' => 'Test', 'en' => 'Test'],
                ],
                $this->operationUser(),
            );
        }
        $c = $this->accountingContext($mode);
        $chart = [
            ...$this->accountingChart(),
            [
                'code' => '55',
                'parent_code' => null,
                'label' => ['fr' => 'Frais fictifs', 'en' => 'Test fee account'],
                'kind' => 'other',
                'auxiliary_type' => 'none',
                'postable' => true,
                'active' => true,
            ],
        ];
        $c['chart'] = $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'TEST-WITHDRAWAL',
            'accounts' => $chart,
        ])
            ->assertOk()
            ->json('data');
        $c['accounts'] = collect($c['chart']['accounts'])->keyBy('code')->all();
        $c['treasury_root'] = $c['root'].'/treasury';
        foreach (['source' => 'monetbil', 'destination' => 'bank'] as $side => $kind) {
            $c[$side] = $this->op('POST', 'treasury/accounts', [
                'code' => 'TEST-'.substr($this->fixtureId(), 0, 8),
                'name' => 'Fictional '.$side,
                'kind' => $kind,
            ])
                ->assertCreated()
                ->json('data');
            $c[$side.'_binding'] = $this->op('POST', $c['treasury_root'].'/bindings', [
                'treasury_account_id' => $c[$side]['id'],
                'account_id' => $c['accounts'][$side === 'source' ? '11' : '22']['id'],
                'inflow_side' => $inflow,
                'version' => 0,
                'note' => 'Test',
            ])
                ->assertCreated()
                ->json('data');
        }
        $c['evidence'] = $this->treasuryEvidence();
        if ($mode === 'live') {
            $this->operationUser('accountant');
            $this->op('POST', $c['root'].'/chart/review', [
                'version' => 2,
                'sha256' => $c['chart']['sha256'],
                'note' => 'Test',
            ])->assertOk();
            foreach (['source', 'destination'] as $side) {
                $this->op('POST', $c['treasury_root'].'/bindings/'.$c[$side.'_binding']['id'].'/review', [
                    'sha256' => $c[$side.'_binding']['sha256'],
                    'note' => 'Test',
                ])->assertOk();
            }
            $this->operationUser();
        }

        return $c;
    }

    protected function withdrawalData(array $c, array $changes = []): array
    {
        return [
            ...[
                'source_binding_id' => $c['source_binding']['id'],
                'destination_binding_id' => $c['destination_binding']['id'],
                'reference' => 'TEST-REQUEST',
                'amount' => '1000',
                'requested_on' => '2026-11-05',
                'requested_at' => '2026-11-05T08:00:00Z',
                'document_id' => $c['evidence']['id'],
                'note' => 'Test external request, no real transfer',
            ],
            ...$changes,
        ];
    }

    protected function withdrawal(array $c, array $changes = [], bool $approve = true): array
    {
        $w = $this->op('POST', $c['treasury_root'].'/withdrawals', $this->withdrawalData($c, $changes))
            ->assertCreated()
            ->json('data');
        if ($approve) {
            $this->operationUser('accountant');
            $w = $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/approve', [
                'version' => 1,
                'sha256' => $w['sha256'],
                'date' => '2026-11-05',
                'note' => 'Test review',
            ])
                ->assertOk()
                ->json('data');
        }

        return $w;
    }

    protected function withdrawalEntry(
        array $c,
        string $gross = '600',
        string $fee = '20',
        string $date = '2026-11-05',
    ): array {
        $net = (string) ((int) $gross - (int) $fee);
        $in = $c['source_binding']['inflow_side'];
        $out = $in === 'debit' ? 'credit' : 'debit';
        $lines = [
            ['account_id' => $c['accounts']['11']['id'], $in => '0', $out => $gross],
            ['account_id' => $c['accounts']['22']['id'], $in => $net, $out => '0'],
        ];
        if ($fee !== '0') {
            $lines[] = ['account_id' => $c['accounts']['55']['id'], $in => $fee, $out => '0'];
        }
        $e = $this->op('POST', $c['root'].'/entries', [
            'journal_id' => $c['journal']['id'],
            'entry_date' => $date,
            'description' => ['fr' => 'Transfert fictif', 'en' => 'Test transfer'],
            'reference' => 'TEST',
            'document_ids' => $c['book']['mode'] === 'live' ? [$c['evidence']['id']] : [],
            'lines' => $lines,
        ])
            ->assertCreated()
            ->json('data');
        $e = $this->accountingPost($c, $e)->assertOk()->json('data');
        foreach (['source' => '11', 'destination' => '22'] as $side => $code) {
            $e[$side.'_line_id'] = (int) DB::table('accounting_entry_lines')
                ->where('entry_id', $e['id'])
                ->where('account_id', $c['accounts'][$code]['id'])
                ->value('id');
        }

        return $e;
    }

    protected function withdrawalReceiptData(array $c, array $entry, array $changes = []): array
    {
        $out = $c['source_binding']['inflow_side'] === 'debit' ? 'credit' : 'debit';
        $gross = $entry['lines'][0][$out];
        $net = $entry['lines'][1][$c['source_binding']['inflow_side']];

        return [
            ...[
                'version' => 2,
                'source_binding_id' => $c['source_binding']['id'],
                'destination_binding_id' => $c['destination_binding']['id'],
                'entry_id' => $entry['id'],
                'source_line_id' => $entry['source_line_id'],
                'destination_line_id' => $entry['destination_line_id'],
                'accepted_gross' => $gross,
                'accepted_net' => $net,
                'accepted_fee' => (string) ((int) $gross - (int) $net),
                'received_at' => '2026-11-05T09:00:00Z',
                'reference' => 'TEST-SETTLEMENT',
                'document_id' => $c['evidence']['id'],
                'note' => 'Test received evidence',
            ],
            ...$changes,
        ];
    }

    protected function withdrawalReceipt(array $c, array $w, array $entry, array $changes = []): array
    {
        return $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $entry, $changes),
        )
            ->assertCreated()
            ->json('data');
    }

    protected function withdrawalReversal(array $c, array $p, string $date = '2026-11-05'): array
    {
        return [
            'version' => $p['version'],
            'sha256' => $p['sha256'],
            'journal_id' => $c['journal']['id'],
            'entry_date' => $date,
            'description' => ['fr' => 'Correction fictive', 'en' => 'Test correction'],
            'document_id' => $c['evidence']['id'],
            'note' => 'Test exact reversal',
        ];
    }
}
