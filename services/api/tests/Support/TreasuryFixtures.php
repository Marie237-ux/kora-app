<?php

namespace Tests\Support;

use App\Domain\Documents\DocumentScanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait TreasuryFixtures
{
    protected function treasuryContext(): array
    {
        $c = $this->accountingContext();
        $c['treasury'] = $this->account();
        $c['treasury_root'] = $c['root'].'/treasury';
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c))
            ->assertCreated()
            ->json('data');
        $c['evidence'] = $this->treasuryEvidence();

        return $c;
    }

    protected function bindingData(array $c, int $version = 0): array
    {
        return [
            'treasury_account_id' => $c['treasury']['id'],
            'account_id' => $c['accounts']['11']['id'],
            'inflow_side' => 'debit',
            'version' => $version,
            'note' => 'Fictional test configuration',
        ];
    }

    protected function treasuryEvidence(): array
    {
        Storage::fake('local');
        $this->app->instance(
            DocumentScanner::class,
            new class implements DocumentScanner
            {
                public function scan(string $path): string
                {
                    return 'clean';
                }
            },
        );

        return $this->post('/api/v1/operations/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'test.pdf',
                "%PDF-1.4\n% Test only, no real funds.\n%%EOF",
            ),
            'purpose' => 'accounting_evidence',
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function statementData(array $c, array $changes = []): array
    {
        return [
            'binding_id' => $c['binding']['id'],
            'reference' => 'TEST-STATEMENT',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'opening_balance' => '0',
            'closing_balance' => '2500',
            'document_id' => $c['evidence']['id'],
            'lines' => [
                [
                    'external_id' => 'TEST-ROW',
                    'booked_on' => '2026-11-04',
                    'reference' => 'TEST',
                    'direction' => 'in',
                    'amount' => '2500',
                ],
            ],
            ...$changes,
        ];
    }

    protected function treasuryPosted(array $c, string $amount = '2500'): array
    {
        $entry = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c, $amount))
            ->assertCreated()
            ->json('data');
        $entry = $this->accountingPost($c, $entry)->assertOk()->json('data');
        $line = DB::table('accounting_entry_lines')
            ->where('entry_id', $entry['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');

        return ['entry' => $entry, 'line_id' => (string) $line];
    }

    protected function treasuryStatement(array $c, array $changes = []): array
    {
        return $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c, $changes))
            ->assertCreated()
            ->json('data');
    }

    protected function matchData(array $s, string $ledgerId, int $version = 1): array
    {
        return [
            'version' => $version,
            'note' => 'Test evidence comparison',
            'statement_line_ids' => DB::table('treasury_statement_lines')
                ->where('statement_id', $s['id'])
                ->orderBy('position')
                ->pluck('id')
                ->all(),
            'ledger_line_ids' => [$ledgerId],
        ];
    }

    protected function outstandingData(array $c, array $s, string $side, string $lineId, int $version = 1): array
    {
        return [
            'version' => $version,
            'side' => $side,
            'line_id' => $lineId,
            'document_id' => $c['evidence']['id'],
            'expected_on' => CarbonImmutable::parse($s['ends_on'])->addWeek()->toDateString(),
            'note' => 'Fictional timing difference supported by test evidence',
        ];
    }

    protected function treasuryCarry(array $c, array $s, string $side, string $lineId): array
    {
        $path = $c['treasury_root'].'/statements/'.$s['id'];
        $item = $this->op('POST', $path.'/outstanding', $this->outstandingData($c, $s, $side, $lineId))
            ->assertCreated()
            ->json('data');
        $this->operationUser('accountant');
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/review', [
            'version' => 2,
            'sha256' => $item['sha256'],
            'note' => 'Test accountant review',
        ])->assertOk();
        $closed = $this->op('POST', $path.'/close', [
            'version' => 3,
            'note' => 'Test close with reviewed timing difference',
        ])
            ->assertOk()
            ->json('data');

        return ['item' => $item, 'closed' => $closed];
    }

    protected function coverageData(array $c, array $s, int $version = 0): array
    {
        return [
            'statement_id' => $s['id'],
            'document_id' => $c['evidence']['id'],
            'version' => $version,
            'note' => 'Fictional coverage and opening evidence',
        ];
    }

    protected function activateCoverage(array $c, array $s): array
    {
        $this->operationUser();
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s))
            ->assertCreated()
            ->json('data');
        $this->operationUser('accountant');

        return $this->op('POST', $c['treasury_root'].'/coverage-policies/'.$p['id'].'/review', [
            'version' => $p['version'],
            'sha256' => $p['sha256'],
            'note' => 'Test accountant opening review',
        ])
            ->assertOk()
            ->json('data');
    }
}
