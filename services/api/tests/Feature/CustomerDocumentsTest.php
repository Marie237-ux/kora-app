<?php

namespace Tests\Feature;

use App\Domain\Documents\CustomerDocuments;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class CustomerDocumentsTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    /** A paid 2 500 XAF order (1 000 item + 1 500 delivery). */
    private function paidOrder(): array
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-PAID', $attempt['id'], 2500);
        $this->notify([
            'transaction_id' => 'TX-PAID',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->client($c['client']);

        return [$c, $order];
    }

    private function identity(array $changes = [])
    {
        $current = $this->op('GET', 'settings/billing')->json('data');

        return $this->op('PUT', 'settings/billing', [
            'version' => $current['version'],
            'legal_name' => 'Exploitant fictif de test SARL',
            'trade_name' => 'KORA',
            'legal_form' => 'SARL',
            'niu' => 'TEST-NIU-0000',
            'rccm' => 'TEST-RCCM-0000',
            'address' => 'Adresse fictive de test',
            'city' => 'Douala',
            'phone' => null,
            'email' => null,
            'tax_regime' => 'vat',
            'vat_rate_basis_points' => 1925,
            'mentions' => ['fr' => 'Mentions de test.', 'en' => 'Test mentions.'],
            ...$changes,
        ]);
    }

    private function issue(array $document)
    {
        return $this->op('POST', 'customer-documents/'.$document['id'].'/issue', [
            'version' => $document['version'],
        ]);
    }

    private function snapshot(string $id): array
    {
        return json_decode(DB::table('customer_documents')->where('id', $id)->value('snapshot'), true);
    }

    public function test_nothing_is_invented_and_documents_stay_demonstrations_until_the_identity_is_complete(): void
    {
        [$c, $order] = $this->paidOrder();

        $this->operationUser('accountant');
        $identity = $this->op('GET', 'settings/billing')->assertOk()->json('data');
        $this->assertSame('unset', $identity['tax_regime']);
        $this->assertEqualsCanonicalizing(
            ['legal_name', 'niu', 'rccm', 'address', 'city', 'tax_regime'],
            $identity['missing'],
        );
        // The identity belongs to the owner alone.
        $this->identity()->assertForbidden();

        $draft = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->assertSame([null, 'draft', true], [$draft['number'], $draft['status'], $draft['demo']]);
        $issued = $this->issue($draft)->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^DEMO-FAC-\d{4}-000001$/', $issued['number']);
        $snapshot = $this->snapshot($issued['id']);
        $this->assertNull($snapshot['seller']['legal_name']);
        // No VAT is charged by default.
        $this->assertNull($snapshot['totals']['vat']);
        $this->assertSame('0f865a8a306233e45f8c318f90f23596bdab7cdd4596a6465c8304d01f30a009', $snapshot['logo_sha256']);

        // Issued once, never again; one invoice per order.
        $this->issue($issued)->assertUnprocessable()->assertJsonValidationErrors('document', 'error.fields');
        $this->op('POST', 'orders/'.$order['id'].'/invoice')->assertConflict();

        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$order['id'].'/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        foreach (['fr', 'en'] as $locale) {
            $pdf = $this->get(
                '/api/v1/orders/'.$order['id'].'/documents/'.$issued['id'].'/pdf?locale='.$locale,
            )
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
    }

    public function test_an_issued_invoice_is_frozen_with_vat_and_corrected_only_by_a_linked_credit_note(): void
    {
        $this->operationUser();
        $this->identity()->assertOk()->assertJsonPath('data.missing', []);
        [, $order] = $this->paidOrder();

        $this->operationUser('owner');
        $invoice = $this->issue($this->op('POST', 'orders/'.$order['id'].'/invoice')->json('data'))
            ->assertOk()
            ->json('data');
        $this->assertMatchesRegularExpression('/^FAC-\d{4}-000001$/', $invoice['number']);
        $this->assertFalse($invoice['demo']);
        // 2 500 tax-inclusive at 19.25 %: VAT 404 (rounded half up), 2 096 excluding VAT.
        $this->assertSame(
            [
                'total' => '2500',
                'vat_rate_basis_points' => 1925,
                'vat' => '404',
                'excluding_vat' => '2096',
                'items' => '1000',
            ],
            $this->snapshot($invoice['id'])['totals'],
        );

        // A later change of identity never rewrites an issued document.
        $this->identity(['legal_name' => 'Nouveau nom fictif'])->assertOk();
        $this->assertSame('Exploitant fictif de test SARL', $this->snapshot($invoice['id'])['seller']['legal_name']);
        $this->assertSame(
            hash('sha256', DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot')),
            $invoice['snapshot_sha256'],
        );

        $refund = $this->refund($order, '1000')->assertCreated()->json('data');
        // A credit note follows a refund that was actually paid.
        $this->op('POST', 'refunds/'.$refund['id'].'/credit-note')->assertUnprocessable();
        $refund = $this->transfer($refund, '1000', 'MM-DOC-1')->assertOk()->json('data');
        $credit = $this->issue(
            $this->op('POST', 'refunds/'.$refund['id'].'/credit-note')
                ->assertCreated()
                ->json('data'),
        )
            ->assertOk()
            ->json('data');
        $this->assertMatchesRegularExpression('/^AV-\d{4}-000001$/', $credit['number']);
        $this->assertSame($invoice['id'], $credit['invoice_id']);
        $this->assertSame($invoice['number'], $this->snapshot($credit['id'])['invoice_number']);
        $this->op('POST', 'refunds/'.$refund['id'].'/credit-note')->assertConflict();
        $this->assertSame('2500', DB::table('customer_documents')->where('id', $invoice['id'])->value('total').'');
    }

    public function test_documents_are_private_and_drafts_are_never_shown_to_customers(): void
    {
        [$c, $order] = $this->paidOrder();
        $this->operationUser('accountant');
        $draft = $this->op('POST', 'orders/'.$order['id'].'/invoice')->json('data');

        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$order['id'].'/documents')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->get('/api/v1/orders/'.$order['id'].'/documents/'.$draft['id'].'/pdf')->assertNotFound();

        $this->operationUser('support');
        $this->assertStringStartsWith(
            '%PDF-',
            $this->get('/api/v1/operations/customer-documents/'.$draft['id'].'/pdf')
                ->assertOk()
                ->getContent(),
        );
        $this->issue($draft)->assertForbidden();

        $this->operationUser('accountant');
        $issued = $this->issue($draft)->assertOk()->json('data');
        $this->client(User::factory()->create(['roles' => ['client']]));
        $this->get('/api/v1/orders/'.$order['id'].'/documents/'.$issued['id'].'/pdf')->assertNotFound();
        $this->getJson('/api/v1/operations/customer-documents')->assertForbidden();
    }

    public function test_vat_extraction_stays_exact_for_very_large_amounts(): void
    {
        $documents = app(CustomerDocuments::class);
        foreach ([0, 1, 2500, 999_999_999_999_999_999, 1_000_000_000_000_000_000] as $total) {
            $totals = $documents->totals($total, 1925);
            $this->assertSame((string) $total, (string) ((int) $totals['vat'] + (int) $totals['excluding_vat']));
            $this->assertGreaterThanOrEqual(0, (int) $totals['vat']);
        }
        $this->assertNull($documents->totals(2500, null)['vat']);
    }
}
