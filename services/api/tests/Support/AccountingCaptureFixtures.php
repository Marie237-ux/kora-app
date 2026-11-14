<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait AccountingCaptureFixtures
{
    protected function accountingFacts(): array
    {
        $this->useFakeGateway();
        $ctx = $this->checkout();
        $order = $this->order($ctx['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TEST-ACCOUNTING-CAPTURE', $attempt['id'], (int) $order['total']);
        $callback = [
            'transaction_id' => 'TEST-ACCOUNTING-CAPTURE',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ];
        $this->notify($callback)->assertOk();
        $this->operationUser();
        $draft = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $document = $this->op('POST', 'customer-documents/'.$draft['id'].'/issue', ['version' => $draft['version']])
            ->assertOk()
            ->json('data');
        $eventId = DB::table('outbox_events')
            ->where('deduplication_key', 'CustomerInvoiceIssued:'.$document['id'])
            ->value('id');

        return compact('ctx', 'order', 'attempt', 'document', 'eventId', 'callback');
    }

    protected function configureAccountingCapture(
        array $c,
        bool $enabled = true,
        int $version = 1,
        ?string $from = '2026-01-01',
    ) {
        return $this->op('PUT', $c['root'].'/capture-settings', [
            'version' => $version,
            'enabled' => $enabled,
            'from' => $from,
            'note' => 'Explicit fictional test capture policy',
        ]);
    }
}
