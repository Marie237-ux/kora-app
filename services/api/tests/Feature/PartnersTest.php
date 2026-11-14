<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class PartnersTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    private function application(array $overrides = []): array
    {
        return [
            'contact_name' => 'Test Contact',
            'business_name' => 'Test Boutique Akwa',
            'activity' => 'Phones and accessories (test only).',
            'phone' => '+237 6 99 00 00 01',
            'email' => 'test-partner@example.test',
            'city' => 'Douala',
            'district' => 'Akwa',
            'product_types' => ['phones', 'accessories'],
            'consent' => true,
            ...$overrides,
        ];
    }

    public function test_an_application_becomes_a_supplier_shop_and_published_partner_without_duplicates(): void
    {
        Storage::fake('local');
        // No partner yet: the page can show an honest invitation instead of invented brands.
        $this->getJson('/api/v1/partners')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_partners', false);

        $this->postJson(
            '/api/v1/partner-applications',
            $this->application(['consent' => false]),
        )->assertUnprocessable();
        $reference = $this->postJson('/api/v1/partner-applications', $this->application())
            ->assertStatus(202)
            ->json('data.reference');
        $this->assertStringStartsWith('CAND-', $reference);
        // Robots filling the trap field get the same answer, and nothing is stored.
        $this->postJson(
            '/api/v1/partner-applications',
            $this->application(['company_site' => 'http://spam.example']),
        )->assertStatus(202);
        $this->assertSame(1, DB::table('partner_applications')->count());
        $this->postJson(
            '/api/v1/partner-applications',
            $this->application(['phone' => '699000001', 'email' => null]),
        )->assertStatus(202);
        $first = DB::table('partner_applications')->where('number', $reference)->first();
        $second = DB::table('partner_applications')->where('number', '!=', $reference)->first();
        $this->assertSame($first->id, $second->duplicate_of);

        $this->operationUser();
        $this->op('POST', 'partner-applications/'.$first->id.'/notes', ['body' => 'Visited the shop (test).'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_review');
        $this->post('/api/v1/operations/partner-applications/'.$first->id.'/attachments', [
            'file' => UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n% Test licence only.\n%%EOF"),
        ])->assertCreated();
        $application = $this->op('GET', 'partner-applications/'.$first->id)->json('data');
        $application = $this->op('POST', 'partner-applications/'.$first->id.'/decision', [
            'version' => $application['version'],
            'decision' => 'accept',
            'note' => 'Real shop, documents checked (test).',
        ])
            ->assertOk()
            ->json('data');
        $application = $this->op('POST', 'partner-applications/'.$first->id.'/conversion', [
            'version' => $application['version'],
            'shop' => [
                'slug' => 'test-boutique-akwa',
                'name' => 'Test Boutique Akwa',
                'description' => ['fr' => 'Boutique de test.', 'en' => 'Test shop.'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'converted')
            ->json('data');
        $this->assertSame(
            'Test Boutique Akwa',
            DB::table('suppliers')->where('id', $application['supplier_id'])->value('name'),
        );
        $this->assertSame('draft', DB::table('shops')->where('id', $application['shop_id'])->value('status'));

        // The duplicate application cannot create a second supplier, nor reuse the converted one.
        $duplicate = $this->op('POST', 'partner-applications/'.$second->id.'/decision', [
            'version' => (int) $second->version,
            'decision' => 'accept',
            'note' => 'Same shop (test).',
        ])->json('data');
        $this->op('POST', 'partner-applications/'.$second->id.'/conversion', ['version' => $duplicate['version']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id', 'error.fields');
        $this->op('POST', 'partner-applications/'.$second->id.'/conversion', [
            'version' => $duplicate['version'],
            'supplier_id' => $application['supplier_id'],
        ])->assertUnprocessable();
        $this->assertSame(1, DB::table('suppliers')->where('name', 'Test Boutique Akwa')->count());

        $partner = $this->op('POST', 'partners', [
            'application_id' => $first->id,
            'name' => 'Test Boutique Akwa',
            'slug' => 'test-boutique-akwa',
            'domain' => ['fr' => 'Téléphonie', 'en' => 'Phones'],
            'presentation' => ['fr' => 'Boutique partenaire de test.', 'en' => ''],
            'advantages' => [
                'fr' => 'Garantie vendeur indiquée par offre.',
                'en' => 'Seller warranty shown per offer.',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $this->op('POST', 'partners/'.$partner['id'].'/status', [
            'version' => $partner['version'],
            'status' => 'published',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('presentation', 'error.fields');
        $partner = $this->op('PUT', 'partners/'.$partner['id'], [
            ...$partner,
            'presentation' => ['fr' => 'Boutique partenaire de test.', 'en' => 'Test partner shop.'],
        ])
            ->assertOk()
            ->json('data');
        $partner = $this->op('POST', 'partners/'.$partner['id'].'/status', [
            'version' => $partner['version'],
            'status' => 'published',
        ])
            ->assertOk()
            ->json('data');

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/partners')
            ->assertOk()
            ->assertJsonPath('meta.has_partners', true)
            ->assertJsonPath('data.0.slug', 'test-boutique-akwa')
            // The shop link appears only once the shop itself is published.
            ->assertJsonPath('data.0.shop', null)
            ->json('data.0');
        $json = json_encode($public);
        $this->assertStringNotContainsString('699', $json);
        $this->assertStringNotContainsString('example.test', $json);
        $this->assertArrayNotHasKey('supplier_id', $public);

        $this->operationUser();
        $this->op('POST', 'partners/'.$partner['id'].'/status', [
            'version' => $partner['version'],
            'status' => 'archived',
        ])->assertOk();
        $this->getJson('/api/v1/partners')->assertJsonCount(0, 'data');
    }

    public function test_rights_and_protected_applications(): void
    {
        $this->postJson('/api/v1/partner-applications', $this->application())->assertStatus(202);
        $id = DB::table('partner_applications')->value('id');
        $this->operationUser('manager');
        $this->op('GET', 'partner-applications')
            ->assertOk()
            ->assertJsonPath('data.0.consent.version', 'partner-application-2026-11');
        $this->op('POST', 'partner-applications/'.$id.'/decision', [
            'version' => 1,
            'decision' => 'accept',
            'note' => 'x',
        ])->assertForbidden();
        $this->operationUser('catalogue');
        $this->op('GET', 'partner-applications')->assertForbidden();
        foreach (
            [
                fn () => DB::table('partner_applications')->delete(),
                fn () => DB::table('partner_applications')->update(['activity' => 'Rewritten', 'version' => 2]),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('An application was rewritten.');
            } catch (QueryException) {
                $this->assertSame(
                    'Phones and accessories (test only).',
                    DB::table('partner_applications')->value('activity'),
                );
            }
        }
    }
}
