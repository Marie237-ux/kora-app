<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait LetteringFixtures
{
    protected function letteringContext(string $type = 'customer'): array
    {
        $c = $this->accountingContext();
        $c['account'] = $c['accounts'][$type === 'customer' ? '33' : '44'];
        if ($type === 'customer') {
            $c['party'] = ['type' => 'customer', 'id' => 'user:'.User::factory()->create()->id];
        } else {
            $id = $this->fixtureId();
            DB::table('suppliers')->insert([
                'id' => $id,
                'name' => 'Fictional test supplier',
                'private_details' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $c['party'] = ['type' => 'supplier', 'id' => 'supplier:'.$id];
        }
        $c['evidence'] = $this->treasuryEvidence();

        return $c;
    }

    protected function letteringEntry(
        array $c,
        string $side,
        string $amount = '1000',
        string $date = '2026-11-04',
        bool $post = true,
    ): array {
        $opposite = $side === 'debit' ? 'credit' : 'debit';
        $data = $this->accountingEntryData($c, $amount, $date);
        $data['lines'] = [
            [
                'account_id' => $c['account']['id'],
                'debit' => $side === 'debit' ? $amount : '0',
                'credit' => $side === 'credit' ? $amount : '0',
                'party' => $c['party'],
            ],
            [
                'account_id' => $c['accounts']['11']['id'],
                'debit' => $opposite === 'debit' ? $amount : '0',
                'credit' => $opposite === 'credit' ? $amount : '0',
            ],
        ];
        $entry = $this->op('POST', $c['root'].'/entries', $data)
            ->assertCreated()
            ->json('data');
        if ($post) {
            $entry = $this->accountingPost($c, $entry)->assertOk()->json('data');
        }
        $entry['line_id'] = (int) DB::table('accounting_entry_lines')
            ->where('entry_id', $entry['id'])
            ->where('account_id', $c['account']['id'])
            ->value('id');

        return $entry;
    }

    protected function letteringData(array $c, array $lines, string $date = '2026-11-04'): array
    {
        return [
            'matched_on' => $date,
            'reference' => 'TEST-LETTERING',
            'note' => 'Fictional test matching',
            'document_id' => $c['evidence']['id'],
            'lines' => $lines,
        ];
    }

    protected function letteringPair(array $a, array $b, string $amount = '1000'): array
    {
        return [
            ['ledger_line_id' => $a['line_id'], 'amount' => $amount],
            ['ledger_line_id' => $b['line_id'], 'amount' => $amount],
        ];
    }
}
