<?php

namespace Tests\Support;

use App\Domain\Documents\DocumentScanner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait ProcurementFixtures
{
    protected function operationUser(string $role = 'owner'): User
    {
        $user = User::factory()->create(['roles' => [$role], 'two_factor_confirmed_at' => now()]);
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')
            ->withSession(['mfa_verified' => true, 'auth.password_confirmed_at' => now()->timestamp])
            ->withHeaders(['Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);

        return $user;
    }

    protected function op(string $method, string $path, array $data = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->json(
            $method,
            '/api/v1/operations/'.$path,
            $data,
        );
    }

    protected function fixtureId(): string
    {
        return (string) Str::uuid();
    }

    protected function evidence(string $supplierId, string $purpose): array
    {
        return $this->post('/api/v1/operations/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'fixture.pdf',
                "%PDF-1.4\n% Test-only document, no legal or commercial effect.\n%%EOF",
            ),
            'purpose' => $purpose,
            'supplier_id' => $supplierId,
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function fixtureCatalogue(string $tracking = 'none', bool $expiry = false): array
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
        $supplier = $this->fixtureId();
        $shop = $this->fixtureId();
        $category = $this->fixtureId();
        $product = $this->fixtureId();
        $variant = $this->fixtureId();
        $offer = $this->fixtureId();
        $localized = json_encode(['fr' => 'Fixture de test', 'en' => 'Test fixture']);
        DB::table('suppliers')->insert([
            'id' => $supplier,
            'name' => 'Test only',
            'private_details' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shops')->insert([
            'id' => $shop,
            'supplier_id' => $supplier,
            'slug' => $shop,
            'name' => 'Test shop',
            'description' => $localized,
            'published' => true,
            'status' => 'published',
            'published_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('categories')->insert([
            'id' => $category,
            'slug' => $category,
            'name' => $localized,
            'published' => true,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'id' => $product,
            'category_id' => $category,
            'slug' => $product,
            'name' => $localized,
            'description' => $localized,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('variants')->insert([
            'id' => $variant,
            'product_id' => $product,
            'sku' => 'TEST-'.$variant,
            'attributes' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offers')->insert([
            'id' => $offer,
            'variant_id' => $variant,
            'shop_id' => $shop,
            'reference_price' => 1000,
            'selling_price' => 1000,
            'status' => 'published',
            'availability' => 'unconfirmed',
            'warranty' => $localized,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_agreements')->insert([
            'id' => $this->fixtureId(),
            'supplier_id' => $supplier,
            'minimum_quantity' => 1,
            'discount_basis_points' => 0,
            'priority' => 0,
            'starts_at' => now()->subDay(),
            'contract_reference' => 'TEST ONLY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->op('PUT', 'inventory/profiles/'.$variant, [
            'tracking_mode' => $tracking,
            'requires_expiry' => $expiry,
            'logistics' => [
                'mass_grams' => null,
                'length_mm' => null,
                'width_mm' => null,
                'height_mm' => null,
                'transport_class' => 'unavailable',
                'handling' => ['fr' => '', 'en' => ''],
            ],
        ])->assertOk();

        return compact('supplier', 'shop', 'category', 'product', 'variant', 'offer');
    }

    protected function contract(
        array $ctx,
        string $mode = 'credit',
        string $basis = 'invoice_date',
        array $extra = [],
    ): array {
        $terms = array_replace(
            [
                'mode' => $mode,
                'due_basis' => $basis,
                'fixed_due_on' => $basis === 'fixed_date' ? now('Africa/Douala')->addDays(45)->toDateString() : null,
                'credit_limit' => null,
                'consignment_basis' => $mode === 'consignment' ? 'approved_statement' : null,
                'schedule' => [['basis_points' => 10000, 'offset_days' => $mode === 'cash' ? 0 : 30]],
            ],
            $extra,
        );
        $contract = $this->op('POST', 'contracts', [
            'supplier_id' => $ctx['supplier'],
            'reference' => 'TEST-'.$this->fixtureId(),
            'starts_on' => now('Africa/Douala')->subDay()->toDateString(),
            'ends_on' => null,
            'text' => ['fr' => 'Contrat fictif réservé aux tests.', 'en' => 'Fictitious contract used only in tests.'],
            'terms' => $terms,
        ])
            ->assertCreated()
            ->json('data');
        foreach (['review', 'approve'] as $action) {
            $contract = $this->op('POST', 'contracts/'.$contract['id'].'/'.$action, [
                'version' => $contract['version'],
            ])
                ->assertOk()
                ->json('data');
        }
        $document = $this->evidence($ctx['supplier'], 'supplier_contract');

        return $this->op('POST', 'contracts/'.$contract['id'].'/accept', [
            'version' => $contract['version'],
            'signed_document_id' => $document['id'],
            'signer_name' => 'Test signer',
            'method' => 'recorded_acceptance',
            'reference' => 'ACCEPTANCE TEST ONLY',
            'accepted_at' => now()->subSecond()->toIso8601String(),
        ])
            ->assertOk()
            ->json('data');
    }

    protected function purchase(
        array $ctx,
        array $contract,
        int $quantity = 100,
        bool $confirm = true,
        string $purpose = 'warehouse_stock',
    ): array {
        $order = $this->op('POST', 'purchases', [
            'supplier_id' => $ctx['supplier'],
            'contract_id' => $contract['id'],
            'purpose' => $purpose,
            'reference' => 'TEST-'.$this->fixtureId(),
            'lines' => [['offer_id' => $ctx['offer'], 'quantity' => $quantity]],
        ])
            ->assertCreated()
            ->json('data');
        if ($confirm) {
            $order = $this->op('POST', 'purchases/'.$order['id'].'/confirm', [
                'version' => $order['version'],
                'supplier_confirmation_reference' => 'CONFIRM TEST ONLY',
            ])
                ->assertOk()
                ->json('data');
        }

        return $order;
    }

    protected function location(): array
    {
        $warehouse = $this->op('POST', 'inventory/warehouses', [
            'code' => 'T-'.substr($this->fixtureId(), 0, 8),
            'name' => 'Test warehouse',
            'address' => 'Test only, not a real address',
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');

        return $this->op('POST', 'inventory/locations', [
            'warehouse_id' => $warehouse['id'],
            'code' => 'TEST',
            'name' => 'Test location',
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function receipt(array $ctx, array $order, array $location, int $quantity, array $overrides = []): array
    {
        $document = $this->evidence($ctx['supplier'], 'stock_evidence');

        return $this->op('POST', 'purchases/'.$order['id'].'/receipts', [
            'location_id' => $location['id'],
            'document_id' => $document['id'],
            'reference' => 'TEST-'.$this->fixtureId(),
            'received_at' => now()->subSecond()->toIso8601String(),
            'lines' => [
                array_replace(
                    [
                        'purchase_line_id' => $order['lines'][0]['id'],
                        'quantity' => $quantity,
                        'quality' => 'available',
                        'identifiers' => [],
                    ],
                    $overrides,
                ),
            ],
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function invoice(array $ctx, array $order, int $quantity, bool $approve = true): array
    {
        $document = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $invoice = $this->op('POST', 'purchase-invoices', [
            'purchase_order_id' => $order['id'],
            'number' => 'TEST-'.$this->fixtureId(),
            'issued_on' => now('Africa/Douala')->toDateString(),
            'document_id' => $document['id'],
            'lines' => [
                ['purchase_line_id' => $order['lines'][0]['id'], 'quantity' => $quantity, 'unit_payable' => 1000],
            ],
            'charges' => [],
        ])
            ->assertCreated()
            ->json('data');

        return $approve
            ? $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/approve', ['version' => $invoice['version']])
                ->assertOk()
                ->json('data')
            : $invoice;
    }

    protected function account(): array
    {
        return $this->op('POST', 'treasury/accounts', [
            'code' => 'TEST-'.substr($this->fixtureId(), 0, 8),
            'name' => 'Test account, no real funds',
            'kind' => 'cash',
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function payment(array $ctx, array $account, string $amount): array
    {
        return $this->op('POST', 'supplier-payments', [
            'supplier_id' => $ctx['supplier'],
            'account_id' => $account['id'],
            'amount' => $amount,
            'reference' => 'INTENT TEST ONLY',
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function verify(
        array $ctx,
        array $payment,
        array $allocations,
        bool $advance = false,
        ?string $actual = null,
    ): array {
        $document = $this->evidence($ctx['supplier'], 'payment_evidence');

        return $this->op('POST', 'supplier-payments/'.$payment['id'].'/verify', [
            'amount' => $actual ?? $payment['amount'],
            'reference' => 'TEST-TRANSACTION-'.$this->fixtureId(),
            'evidence_id' => $document['id'],
            'paid_at' => now()->subSecond()->toIso8601String(),
            'allow_advance' => $advance,
            'allocations' => $allocations,
        ])
            ->assertOk()
            ->json('data');
    }

    protected function inventoryPolicy(): void
    {
        $this->op('PUT', 'settings/inventory', [
            'reservation_ttl_seconds' => 600,
            'reason' => 'Explicit test policy only',
        ])->assertOk();
    }
}
