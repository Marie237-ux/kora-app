<?php

namespace Tests\Support;

use App\Domain\Documents\BillingIdentity;
use Illuminate\Support\Facades\DB;

trait FinancingFixtures
{
    protected function financingContext(string $mode = 'demo'): array
    {
        if ($mode === 'live') {
            app(BillingIdentity::class)->save(
                [
                    'legal_name' => 'Fictional financing operator',
                    'niu' => 'TEST',
                    'rccm' => 'TEST',
                    'address' => 'Test',
                    'city' => 'Test',
                    'tax_regime' => 'exempt',
                    'mentions' => ['fr' => 'Test', 'en' => 'Test'],
                ],
                $this->operationUser(),
            );
        }
        $c = $this->accountingContext($mode);
        $chart = $this->accountingChart();
        $chart[0]['kind'] = 'asset';
        foreach (['66' => 'liability', '77' => 'equity'] as $code => $kind) {
            $chart[] = [
                'code' => (string) $code,
                'parent_code' => null,
                'label' => ['fr' => 'Compte fictif', 'en' => 'Test account'],
                'kind' => $kind,
                'auxiliary_type' => 'none',
                'postable' => true,
                'active' => true,
            ];
        }
        $c['chart'] = $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'TEST-FINANCING',
            'accounts' => $chart,
        ])
            ->assertOk()
            ->json('data');
        $c['accounts'] = collect($c['chart']['accounts'])->keyBy('code')->all();
        $c['treasury'] = $this->account();
        $c['treasury_root'] = $c['root'].'/treasury';
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c))
            ->assertCreated()
            ->json('data');
        $c['evidence'] = $this->treasuryEvidence();
        if ($mode === 'live') {
            $this->operationUser('accountant');
            $this->op('POST', $c['root'].'/chart/review', [
                'version' => 2,
                'sha256' => $c['chart']['sha256'],
                'note' => 'Test',
            ])->assertOk();
            $this->op('POST', $c['treasury_root'].'/bindings/'.$c['binding']['id'].'/review', [
                'sha256' => $c['binding']['sha256'],
                'note' => 'Test',
            ])->assertOk();
            $this->operationUser();
        }

        return $c;
    }

    protected function financingData(array $c, array $changes = []): array
    {
        return [
            ...[
                'kind' => 'loan',
                'reference' => 'TEST-AGREEMENT',
                'counterparty' => 'Fictional lender',
                'account_id' => $c['accounts']['66']['id'],
                'principal' => '1000',
                'effective_on' => '2026-11-05',
                'due_on' => '2026-11-10',
                'document_id' => $c['evidence']['id'],
                'note' => 'Test principal only',
            ],
            ...$changes,
        ];
    }

    protected function financing(array $c, array $changes = [], bool $approve = true): array
    {
        $f = $this->op('POST', $c['treasury_root'].'/financings', $this->financingData($c, $changes))
            ->assertCreated()
            ->json('data');
        if ($approve) {
            $this->operationUser('accountant');
            $f = $this->op('POST', $c['treasury_root'].'/financings/'.$f['id'].'/approve', [
                'version' => 1,
                'sha256' => $f['sha256'],
                'date' => '2026-11-05',
                'note' => 'Test',
            ])
                ->assertOk()
                ->json('data');
        }

        return $f;
    }

    protected function financingEntry(
        array $c,
        string $amount = '600',
        string $direction = 'funding',
        string $account = '66',
    ): array {
        $in = $direction === 'funding' ? 'debit' : 'credit';
        $out = $in === 'debit' ? 'credit' : 'debit';
        $e = $this->op('POST', $c['root'].'/entries', [
            'journal_id' => $c['journal']['id'],
            'entry_date' => '2026-11-05',
            'description' => ['fr' => 'Financement fictif', 'en' => 'Test financing'],
            'reference' => 'TEST',
            'document_ids' => $c['book']['mode'] === 'live' ? [$c['evidence']['id']] : [],
            'lines' => [
                ['account_id' => $c['accounts']['11']['id'], $in => $amount, $out => '0'],
                ['account_id' => $c['accounts'][$account]['id'], $in => '0', $out => $amount],
            ],
        ])
            ->assertCreated()
            ->json('data');
        $e = $this->accountingPost($c, $e)->assertOk()->json('data');
        $e['cash_line_id'] = (int) DB::table('accounting_entry_lines')
            ->where('entry_id', $e['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');

        return $e;
    }

    protected function financingMovementData(array $c, array $e, array $changes = []): array
    {
        return [
            ...[
                'version' => 2,
                'direction' => 'funding',
                'binding_id' => $c['binding']['id'],
                'entry_id' => $e['id'],
                'cash_line_id' => $e['cash_line_id'],
                'accepted_amount' => $e['lines'][0]['debit'] !== '0' ? $e['lines'][0]['debit'] : $e['lines'][0]['credit'],
                'occurred_at' => '2026-11-05T09:00:00Z',
                'reference' => 'TEST-MOVEMENT',
                'document_id' => $c['evidence']['id'],
                'note' => 'Test actual principal',
                'replaces_id' => null,
            ],
            ...$changes,
        ];
    }

    protected function financingMovement(array $c, array $f, array $e, array $changes = []): array
    {
        return $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $e, $changes),
        )
            ->assertCreated()
            ->json('data');
    }

    protected function financingReversal(array $c, array $m): array
    {
        return [
            'version' => $m['version'],
            'sha256' => $m['sha256'],
            'journal_id' => $c['journal']['id'],
            'entry_date' => '2026-11-05',
            'description' => ['fr' => 'Correction fictive', 'en' => 'Test correction'],
            'document_id' => $c['evidence']['id'],
            'note' => 'Test exact reversal',
        ];
    }
}
