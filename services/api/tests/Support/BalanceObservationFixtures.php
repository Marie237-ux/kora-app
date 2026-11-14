<?php

namespace Tests\Support;

trait BalanceObservationFixtures
{
    protected function balanceObservationData(array $c, array $changes = []): array
    {
        return [
            ...[
                'binding_id' => $c['binding']['id'],
                'sequence' => 0,
                'reference' => 'TEST-BALANCE',
                'reported_balance' => '1000',
                'available_funds' => '800',
                'withdrawable_funds' => null,
                'observed_at' => '2026-11-05T10:00:00Z',
                'expires_at' => '2026-11-05T12:00:00Z',
                'document_id' => $c['evidence']['id'],
                'note' => 'Fictional evidence, no real funds',
            ],
            ...$changes,
        ];
    }

    protected function balanceObservation(array $c, array $changes = [], bool $approve = true): array
    {
        $o = $this->op(
            'POST',
            $c['treasury_root'].'/balance-observations',
            $this->balanceObservationData($c, $changes),
        )
            ->assertCreated()
            ->json('data');
        if ($approve) {
            $this->operationUser('accountant');
            $o = $this->op('POST', $c['treasury_root'].'/balance-observations/'.$o['id'].'/approve', [
                'version' => 1,
                'sha256' => $o['sha256'],
                'note' => 'Test review',
            ])
                ->assertOk()
                ->json('data');
        }

        return $o;
    }
}
