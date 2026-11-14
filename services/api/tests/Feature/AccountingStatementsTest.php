<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class AccountingStatementsTest extends TestCase
{
    use AccountingFixtures,
        DeliveryFixtures,
        OrderFixtures,
        PreparedOrderFixtures,
        ProcurementFixtures,
        RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function posted(
        array $c,
        string $debit,
        string $credit,
        string $amount,
        string $reference = 'TEST',
        string $date = '2026-11-04',
    ): void {
        $data = $this->accountingEntryData($c, $amount, $date);
        $data['reference'] = $reference;
        $data['lines'][0]['account_id'] = $c['accounts'][$debit]['id'];
        $data['lines'][1]['account_id'] = $c['accounts'][$credit]['id'];
        $entry = $this->op('POST', $c['root'].'/entries', $data)
            ->assertCreated()
            ->json('data');
        $this->accountingPost($c, $entry)->assertOk();
    }

    private function line(string $code, string $type, array $extra): array
    {
        return [
            'code' => $code,
            'label' => ['fr' => 'Rubrique '.$code, 'en' => 'Line '.$code],
            'type' => $type,
            ...$extra,
        ];
    }

    private function period(array $c, string $from = '2026-01-01', string $to = '2026-12-31'): string
    {
        return http_build_query(['year_id' => $c['year']['id'], 'from' => $from, 'to' => $to]);
    }

    public function test_statements_follow_the_examined_layout_and_say_why_they_are_provisional(): void
    {
        $c = $this->accountingContext();
        $this->posted($c, '11', '22', '2500');
        $this->posted($c, '22', '11', '1000');
        $label = ['fr' => 'Compte de résultat (test)', 'en' => 'Income statement (test)'];

        // Before any layout: no statement is produced from guesses.
        $this->op('GET', $c['root'].'/statements/income_statement?'.$this->period($c))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout', 'error.fields');

        // Overlapping prefixes, unknown total parts and missing balance sheet sides are refused.
        $this->op('POST', $c['root'].'/statement-layouts/income_statement', [
            'label' => $label,
            'scope' => ['1', '2'],
            'lines' => [
                $this->line('A', 'accounts', ['accounts' => ['1'], 'sign' => 'debit']),
                $this->line('B', 'accounts', ['accounts' => ['11'], 'sign' => 'debit']),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.1.accounts', 'error.fields');
        $this->op('POST', $c['root'].'/statement-layouts/income_statement', [
            'label' => $label,
            'scope' => ['1'],
            'lines' => [$this->line('T', 'total', ['components' => [['code' => 'X', 'factor' => 1]]])],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.components', 'error.fields');
        $this->op('POST', $c['root'].'/statement-layouts/balance_sheet', [
            'label' => $label,
            'scope' => ['1'],
            'lines' => [$this->line('A', 'accounts', ['accounts' => ['11'], 'sign' => 'debit'])],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.side', 'error.fields');

        // A first layout leaves account 22 out: it is listed, not hidden.
        $first = $this->op('POST', $c['root'].'/statement-layouts/income_statement', [
            'label' => $label,
            'scope' => ['1', '2'],
            'lines' => [$this->line('CH', 'accounts', ['accounts' => ['11'], 'sign' => 'debit'])],
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->json('data');
        $statement = $this->op('GET', $c['root'].'/statements/income_statement?'.$this->period($c))
            ->assertOk()
            ->json('data');
        $this->assertSame('1500', $statement['lines'][0]['amount']);
        $this->assertSame(
            [
                [
                    'code' => '22',
                    'label' => ['fr' => 'Compte fictif 22', 'en' => 'Test account 22'],
                    'balance' => '-1500',
                ],
            ],
            $statement['unassigned_accounts'],
        );
        $this->assertSame('provisional', $statement['status']);
        $this->assertContains('layout_not_reviewed', array_column($statement['provisional_reasons'], 'code'));
        $this->assertContains('periods_open', array_column($statement['provisional_reasons'], 'code'));
        $this->assertFalse($statement['regulatory_certification']);

        $second = $this->op('POST', $c['root'].'/statement-layouts/income_statement', [
            'label' => $label,
            'scope' => ['1', '2'],
            'lines' => [
                $this->line('PR', 'accounts', ['accounts' => ['22'], 'sign' => 'credit']),
                $this->line('CH', 'accounts', ['accounts' => ['11'], 'sign' => 'debit']),
                $this->line('RES', 'total', [
                    'components' => [['code' => 'PR', 'factor' => 1], ['code' => 'CH', 'factor' => -1]],
                ]),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->json('data');
        $statement = $this->op('GET', $c['root'].'/statements/income_statement?'.$this->period($c))
            ->assertOk()
            ->json('data');
        $this->assertSame(['1500', '1500', '0'], array_column($statement['lines'], 'amount'));
        $this->assertSame([], $statement['unassigned_accounts']);
        // Period movements only: nothing before 1 November.
        $this->assertSame(
            ['0', '0', '0'],
            array_column(
                $this->op(
                    'GET',
                    $c['root'].'/statements/income_statement?'.$this->period($c, '2026-01-01', '2026-10-31'),
                )->json('data.lines'),
                'amount',
            ),
        );

        // Examined by the accountant only; the examined layout is then used and frozen.
        $this->op('POST', $c['root'].'/statement-layouts/'.$second['id'].'/review', [
            'sha256' => $second['sha256'],
            'note' => 'Examen test',
        ])->assertForbidden();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/statement-layouts/'.$second['id'].'/review', [
            'sha256' => str_repeat('0', 64),
            'note' => 'Examen test',
        ])->assertStatus(409);
        $this->op('POST', $c['root'].'/statement-layouts/'.$second['id'].'/review', [
            'sha256' => $second['sha256'],
            'note' => 'Examen test',
        ])->assertOk();
        $this->op('POST', $c['root'].'/statement-layouts/'.$second['id'].'/review', [
            'sha256' => $second['sha256'],
            'note' => 'Again',
        ])->assertUnprocessable();
        try {
            DB::transaction(fn () => DB::table('accounting_statement_layouts')->where('id', $first['id'])->delete());
            $this->fail('Layouts are kept.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ACCOUNTING_LAYOUT_IMMUTABLE', $e->getMessage());
        }
        $statement = $this->op('GET', $c['root'].'/statements/income_statement?'.$this->period($c))->json('data');
        $this->assertTrue($statement['layout']['reviewed']);
        $this->assertNotContains('layout_not_reviewed', array_column($statement['provisional_reasons'], 'code'));

        // Balance sheet: position at the end date, totals by side and their difference shown.
        $this->operationUser();
        $this->op('POST', $c['root'].'/statement-layouts/balance_sheet', [
            'label' => ['fr' => 'Bilan (test)', 'en' => 'Balance sheet (test)'],
            'scope' => ['1', '2'],
            'lines' => [
                $this->line('ACT', 'accounts', ['accounts' => ['11'], 'sign' => 'debit', 'side' => 'assets']),
                $this->line('PAS', 'accounts', ['accounts' => ['22'], 'sign' => 'credit', 'side' => 'liabilities']),
            ],
        ])->assertCreated();
        $sheet = $this->op(
            'GET',
            $c['root'].'/statements/balance_sheet?'.$this->period($c, '2026-11-01', '2026-11-30'),
        )
            ->assertOk()
            ->json('data');
        $this->assertSame('2026-01-01', $sheet['from']);
        $this->assertSame(['assets' => '1500', 'liabilities' => '1500', 'difference' => '0'], $sheet['sides']);

        $this->operationUser('support');
        $this->op('GET', $c['root'].'/statements/income_statement?'.$this->period($c))->assertForbidden();
    }

    public function test_exports_are_exact_protected_against_formulas_confirmed_and_traced(): void
    {
        $c = $this->accountingContext();
        $this->posted($c, '11', '22', '2500', '=HYPERLINK("http://x.test")');
        $this->posted($c, '22', '11', '1000');
        $query = $this->period($c);

        // A sensitive export needs a recent identity confirmation.
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('GET', $c['root'].'/exports/ledger?format=csv&'.$query)->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $csv = $this->op('GET', $c['root'].'/exports/ledger?format=csv&'.$query)->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\r\n", substr($content, 3))));
        $this->assertSame(
            ['Compte', 'Intitulé', 'Date', 'Journal', 'N° écriture', 'Référence', 'Tiers', 'Débit', 'Crédit', 'Solde'],
            $lines[0],
        );
        $this->assertSame("'=HYPERLINK(\"http://x.test\")", $lines[2][5]);
        // Account 11: opening 0, +2500, -1000, running balance exact.
        $this->assertSame(['0', '2500', '1500'], [$lines[1][9], $lines[2][9], $lines[3][9]]);
        $this->assertSame('-1500', collect($lines)->where(0, '22')->last()[9]);
        $this->assertSame(hash('sha256', $content), $csv->headers->get('X-Export-Sha256'));

        $journal = $this->op('GET', $c['root'].'/exports/journal?format=xlsx&'.$query)->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($journal->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('<t xml:space="preserve">JournalCode</t>', $sheet);
        $this->assertStringContainsString('<c t="n"><v>2500</v></c>', $sheet);
        $this->assertStringContainsString('&apos;=HYPERLINK', $sheet);

        $pdf = $this->op('GET', $c['root'].'/exports/trial-balance?format=pdf&'.$query)->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf->baseResponse->getFile()->getPathname()));
        $this->op('GET', $c['root'].'/exports/income-statement?format=csv&'.$query)->assertUnprocessable();

        $history = $this->op('GET', $c['root'].'/exports')
            ->assertOk()
            ->json('data');
        $this->assertEqualsCanonicalizing(['trial-balance', 'journal', 'ledger'], array_column($history, 'report'));
        // Two accounts, each opened with its earlier balance then its two lines.
        $this->assertSame(6, collect($history)->firstWhere('report', 'ledger')['rows']);
        try {
            DB::transaction(fn () => DB::table('accounting_exports')->update(['rows' => 0]));
            $this->fail('Export traces are kept.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ACCOUNTING_EXPORT_IMMUTABLE', $e->getMessage());
        }
        $this->operationUser('manager');
        $this->op('GET', $c['root'].'/exports/ledger?format=csv&'.$query)->assertForbidden();
    }

    public function test_contribution_margin_uses_traced_costs_and_names_unknown_ones(): void
    {
        $o = $this->warehouseOrder(2);
        $this->operationUser();
        $line = DB::table('order_lines')->where('order_id', $o['order']['id'])->first();
        $unitCost = (int) DB::table('stock_lots')->value('unit_cost');
        $today = now('Africa/Douala')->toDateString();

        $byProduct = $this->op(
            'GET',
            'finance/margins?'.http_build_query(['from' => $today, 'to' => $today, 'group' => 'product']),
        )
            ->assertOk()
            ->json('data');
        $group = $byProduct['groups'][0];
        $this->assertSame($o['ctx']['product'], $group['key']);
        $this->assertSame(2, $group['quantity']);
        $this->assertSame((string) $line->line_total, $group['revenue']);
        $this->assertSame((string) (2 * $unitCost), $group['goods_cost_actual']);
        $this->assertSame('0', $group['goods_cost_estimated']);
        $this->assertSame('actual', $group['cost_basis']);
        $this->assertSame((string) ($line->line_total - 2 * $unitCost), $group['contribution']);
        $this->assertSame(['payment_fees', 'delivery_cost'], $byProduct['unknown_costs']);
        $this->assertFalse($byProduct['accounting_result']);

        $byOrder = $this->op(
            'GET',
            'finance/margins?'.http_build_query(['from' => $today, 'to' => $today, 'group' => 'order']),
        )->json('data');
        $this->assertSame('1000', $byOrder['groups'][0]['delivery_collected']);
        $this->assertSame(1, $byOrder['groups'][0]['orders']);
        $empty = $this->op(
            'GET',
            'finance/margins?'.http_build_query(['from' => '2026-01-01', 'to' => '2026-01-31', 'group' => 'shop']),
        )->json('data');
        $this->assertSame([], $empty['groups']);
        $this->assertSame('0', $empty['total']['contribution']);

        $this->operationUser('manager');
        $this->op(
            'GET',
            'finance/margins?'.http_build_query(['from' => $today, 'to' => $today, 'group' => 'order']),
        )->assertForbidden();
    }
}
