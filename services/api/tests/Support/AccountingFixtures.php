<?php

namespace Tests\Support;

trait AccountingFixtures
{
    protected function accountingChart(): array
    {
        // Fictional test-only codes; never seeded or offered as a regulatory chart.
        return array_map(
            fn ($code) => [
                'code' => $code,
                'parent_code' => null,
                'label' => ['fr' => 'Compte fictif '.$code, 'en' => 'Test account '.$code],
                'kind' => 'other',
                'auxiliary_type' => $code === '33' ? 'customer' : ($code === '44' ? 'supplier' : 'none'),
                'postable' => true,
                'active' => true,
            ],
            ['11', '22', '33', '44'],
        );
    }

    protected function accountingContext(string $mode = 'demo', string $endsOn = '2026-12-31'): array
    {
        $actor = $this->operationUser();
        $book = $this->op('POST', 'accounting/books', [
            'mode' => $mode,
            'label' => ['fr' => 'Livre fictif', 'en' => 'Test book'],
        ])
            ->assertCreated()
            ->json('data');
        $root = 'accounting/books/'.$book['id'];
        $chart = $this->op('PUT', $root.'/chart', [
            'version' => 0,
            'reference' => 'TEST-ONLY',
            'accounts' => $this->accountingChart(),
        ])
            ->assertOk()
            ->json('data');
        $accounts = collect($chart['accounts'])->keyBy('code')->all();
        $journal = $this->op('POST', $root.'/journals', [
            'code' => 'TEST',
            'label' => ['fr' => 'Journal fictif', 'en' => 'Test journal'],
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
        $year = $this->op('POST', $root.'/years', [
            'label' => ['fr' => 'Exercice fictif', 'en' => 'Test year'],
            'starts_on' => '2026-01-01',
            'ends_on' => $endsOn,
        ])
            ->assertCreated()
            ->json('data');
        $period = $this->op('POST', $root.'/years/'.$year['id'].'/periods', [
            'label' => ['fr' => 'Période fictive', 'en' => 'Test period'],
            'starts_on' => '2026-01-01',
            'ends_on' => $endsOn,
        ])
            ->assertCreated()
            ->json('data');

        return compact('actor', 'book', 'root', 'chart', 'accounts', 'journal', 'year', 'period');
    }

    protected function accountingEntryData(array $c, string $amount = '2500', string $date = '2026-11-04'): array
    {
        return [
            'journal_id' => $c['journal']['id'],
            'entry_date' => $date,
            'description' => ['fr' => 'Opération fictive de test', 'en' => 'Fictional test entry'],
            'reference' => 'TEST',
            'document_ids' => [],
            'lines' => [
                ['account_id' => $c['accounts']['11']['id'], 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $c['accounts']['22']['id'], 'debit' => '0', 'credit' => $amount],
            ],
        ];
    }

    protected function accountingPost(array $c, array $entry)
    {
        return $this->op('POST', $c['root'].'/entries/'.$entry['id'].'/post', ['version' => $entry['version']]);
    }
}
