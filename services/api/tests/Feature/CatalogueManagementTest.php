<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogueManagementTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'owner'): User
    {
        $user = User::factory()->create(['roles' => [$role], 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user)
            ->withSession(['mfa_verified' => true])
            ->withHeaders(['Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);

        return $user;
    }

    private function create(string $entity, array $draft, bool $publish = true): array
    {
        $record = $this->postJson('/api/v1/operations/catalogue/'.$entity, ['draft' => $draft])
            ->assertCreated()
            ->json('data');
        if ($publish && $entity !== 'suppliers') {
            $record = $this->postJson('/api/v1/operations/catalogue/'.$entity.'/'.$record['id'].'/publish', [
                'version' => $record['version'],
            ])
                ->assertOk()
                ->json('data');
        }

        return $record;
    }

    private function image(bool $publish = true): array
    {
        Storage::fake('local');
        $image = $this->post('/api/v1/operations/media', [
            'file' => UploadedFile::fake()->image('fixture.png', 80, 120),
            'alt' => ['fr' => 'Image de test', 'en' => 'Test image'],
        ])
            ->assertCreated()
            ->json('data');
        if ($publish) {
            $image = $this->postJson('/api/v1/operations/media/'.$image['id'].'/visibility', [
                'version' => $image['version'],
                'is_public' => true,
                'rights_confirmed' => true,
            ])
                ->assertOk()
                ->json('data');
        }

        return $image;
    }

    private function catalogue(array $attributes = [], array $values = []): array
    {
        $supplier = $this->create('suppliers', [
            'name' => 'Supplier fixture',
            'private_details' => [
                'contact_name' => null,
                'email' => null,
                'phone' => null,
                'address' => null,
                'legal_name' => null,
            ],
        ]);
        $shop = $this->create('shops', [
            'supplier_id' => $supplier['id'],
            'slug' => 'fixture-shop',
            'name' => 'Shop fixture',
            'description' => ['fr' => 'Boutique de test', 'en' => 'Test shop'],
        ]);
        $category = $this->create('categories', [
            'slug' => 'fixture-category',
            'parent_id' => null,
            'name' => ['fr' => 'Test', 'en' => 'Test'],
            'attributes' => $attributes,
        ]);
        $product = $this->create('products', [
            'category_id' => $category['id'],
            'slug' => 'fixture-product',
            'name' => ['fr' => 'Produit de test', 'en' => 'Test product'],
            'description' => ['fr' => 'Description de test', 'en' => 'Test description'],
            'variants' => [['sku' => 'FIXTURE-1', 'attributes' => $values]],
        ]);

        return compact('supplier', 'shop', 'category', 'product');
    }

    private function agreement(string $supplierId, array $overrides = []): array
    {
        return $this->postJson(
            '/api/v1/operations/pricing/agreements',
            array_replace(
                [
                    'supplier_id' => $supplierId,
                    'category_id' => null,
                    'product_id' => null,
                    'minimum_quantity' => 1,
                    'priority' => 0,
                    'mode' => 'discount',
                    'discount_basis_points' => 300,
                    'fixed_cost' => null,
                    'starts_at' => now()->subDay()->toIso8601String(),
                    'ends_at' => null,
                    'contract_reference' => 'CONTRACT-FIXTURE',
                ],
                $overrides,
            ),
        )
            ->assertCreated()
            ->json('data');
    }

    private function offer(array $context, string $image, ?array $promotion = null, bool $publish = true): array
    {
        return $this->create(
            'offers',
            [
                'shop_id' => $context['shop']['id'],
                'variant_id' => $context['product']['draft']['variants'][0]['id'],
                'reference_price' => 5000,
                'minimum_quantity' => 1,
                'availability' => 'unconfirmed',
                'warranty' => [
                    'fr' => 'Conditions de test, sans promesse commerciale',
                    'en' => 'Test terms, no commercial promise',
                ],
                'image_path' => $image,
                'promotion' => $promotion,
            ],
            $publish,
        );
    }

    public function test_authorized_catalogue_can_be_created_published_and_read_without_private_costs(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $agreement = $this->agreement($context['supplier']['id']);
        $offer = $this->offer($context, $media['url']);
        $public = $this->getJson('/api/v1/catalogue/'.$offer['id'])
            ->assertOk()
            ->assertJsonPath('data.price.amount', 5000)
            ->assertJsonPath('data.shop.name', 'Shop fixture')
            ->assertJsonPath('data.image', $media['url'])
            ->assertJsonMissingPath('data.purchase_unit')
            ->assertJsonMissingPath('data.draft')
            ->assertJsonMissingPath('data.supplier_id');
        $quote = $this->getJson(
            '/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1',
        )->assertOk();
        $quote
            ->assertJsonPath('data.calculation.purchase_unit', 4850)
            ->assertJsonPath('data.calculation.gross_difference', '150')
            ->assertJsonPath('data.agreement.id', $agreement['id']);
        $this->get($media['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/catalogue?q=Test&locale=en')->assertJsonCount(1, 'data');
    }

    public function test_saved_offer_changes_remain_private_until_publication_and_old_snapshots_survive(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $this->agreement($context['supplier']['id']);
        $offer = $this->offer($context, $media['url']);
        $draft = $offer['draft'];
        $draft['reference_price'] = 6000;
        $saved = $this->putJson('/api/v1/operations/catalogue/offers/'.$offer['id'], [
            'version' => $offer['version'],
            'draft' => $draft,
        ])
            ->assertOk()
            ->json('data');
        $this->getJson('/api/v1/catalogue/'.$offer['id'])->assertJsonPath('data.price.amount', 5000);
        $this->postJson('/api/v1/operations/catalogue/offers/'.$offer['id'].'/publish', [
            'version' => $saved['version'],
        ])->assertOk();
        $this->getJson('/api/v1/catalogue/'.$offer['id'])->assertJsonPath('data.price.amount', 6000);
        $first = DB::table('catalogue_revisions')
            ->where('entity', 'offers')
            ->where('resource_id', $offer['id'])
            ->where('action', 'publish')
            ->orderBy('version')
            ->first();
        $this->assertSame(4850, json_decode($first->snapshot, true)['commercial']['calculation']['purchase_unit']);
        $this->putJson('/api/v1/operations/catalogue/offers/'.$offer['id'], [
            'version' => $offer['version'],
            'draft' => $draft,
        ])->assertConflict();
    }

    public function test_kora_promotion_keeps_supplier_cost_and_expires_without_a_scheduler_or_false_discount(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $this->agreement($context['supplier']['id']);
        $promotion = [
            'price' => 4900,
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'kora_funding' => 100,
            'supplier_funding' => 0,
            'contract_reference' => null,
            'negative_margin_reason' => null,
        ];
        $offer = $this->offer($context, $media['url'], $promotion);
        $this->getJson('/api/v1/catalogue/'.$offer['id'])->assertJsonPath('data.price.amount', 4900);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')
            ->assertJsonPath('data.calculation.purchase_unit', 4850)
            ->assertJsonPath('data.calculation.gross_difference', '50');
        $this->travel(2)->hours();
        $this->getJson('/api/v1/catalogue/'.$offer['id'])->assertJsonPath('data.price.amount', 5000);
    }

    public function test_typed_attributes_drive_validation_and_public_filters_without_code_changes(): void
    {
        $this->staff();
        $definition = [
            'key' => 'material',
            'type' => 'choice',
            'label' => ['fr' => 'Matière', 'en' => 'Material'],
            'unit' => null,
            'required' => true,
            'filterable' => true,
            'options' => [
                ['value' => 'cotton', 'label' => ['fr' => 'Coton', 'en' => 'Cotton']],
                ['value' => 'linen', 'label' => ['fr' => 'Lin', 'en' => 'Linen']],
            ],
        ];
        $context = $this->catalogue([$definition], ['material' => 'cotton']);
        $media = $this->image();
        $this->agreement($context['supplier']['id']);
        $this->offer($context, $media['url']);
        $this->getJson('/api/v1/categories?locale=en')->assertJsonPath('data.0.attributes.0.label', 'Material');
        $this->getJson('/api/v1/catalogue?category=fixture-category&filters[material]=cotton')->assertJsonCount(
            1,
            'data',
        );
        $this->getJson('/api/v1/catalogue?category=fixture-category&filters[material]=linen')->assertJsonCount(
            0,
            'data',
        );
        $this->getJson('/api/v1/catalogue?category=fixture-category&filters[unknown]=x')->assertUnprocessable();
        $product = $context['product'];
        $draft = $product['draft'];
        $draft['variants'][0]['attributes'] = [];
        $updated = $this->putJson('/api/v1/operations/catalogue/products/'.$product['id'], [
            'draft' => $draft,
            'version' => $product['version'],
        ])
            ->assertOk()
            ->json('data');
        $this->postJson('/api/v1/operations/catalogue/products/'.$product['id'].'/publish', [
            'version' => $updated['version'],
        ])->assertUnprocessable();
        $this->getJson('/api/v1/catalogue')->assertJsonCount(1, 'data');
    }

    public function test_category_updates_cannot_silently_invalidate_published_product_attributes_or_form_a_cycle(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $category = $context['category'];
        $draft = $category['draft'];
        $draft['attributes'][] = [
            'key' => 'length',
            'type' => 'number',
            'label' => ['fr' => 'Longueur', 'en' => 'Length'],
            'unit' => 'cm',
            'required' => true,
            'filterable' => true,
            'options' => [],
        ];
        $saved = $this->putJson('/api/v1/operations/catalogue/categories/'.$category['id'], [
            'draft' => $draft,
            'version' => $category['version'],
        ])
            ->assertOk()
            ->json('data');
        $this->postJson('/api/v1/operations/catalogue/categories/'.$category['id'].'/publish', [
            'version' => $saved['version'],
        ])->assertUnprocessable();
        $this->assertSame([], json_decode(DB::table('categories')->find($category['id'])->attribute_definitions, true));
        $draft = $category['draft'];
        $draft['parent_id'] = $category['id'];
        $saved = $this->putJson('/api/v1/operations/catalogue/categories/'.$category['id'], [
            'draft' => $draft,
            'version' => $saved['version'],
        ])
            ->assertOk()
            ->json('data');
        $this->postJson('/api/v1/operations/catalogue/categories/'.$category['id'].'/publish', [
            'version' => $saved['version'],
        ])->assertUnprocessable();
    }

    public function test_old_shop_slug_resolves_to_the_new_canonical_shop_and_cannot_be_taken(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $shop = $context['shop'];
        $draft = $shop['draft'];
        $draft['slug'] = 'renamed-shop';
        $saved = $this->putJson('/api/v1/operations/catalogue/shops/'.$shop['id'], [
            'draft' => $draft,
            'version' => $shop['version'],
        ])
            ->assertOk()
            ->json('data');
        $this->getJson('/api/v1/shops/fixture-shop')->assertJsonPath('data.slug', 'fixture-shop');
        $this->postJson('/api/v1/operations/catalogue/shops/'.$shop['id'].'/publish', [
            'version' => $saved['version'],
        ])->assertOk();
        $this->getJson('/api/v1/shops/fixture-shop')->assertOk()->assertJsonPath('data.slug', 'renamed-shop');
        $another = $draft;
        $another['slug'] = 'fixture-shop';
        $this->postJson('/api/v1/operations/catalogue/shops', ['draft' => $another])->assertUnprocessable();
    }

    public function test_variant_from_another_product_is_never_substituted_silently(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $other = $context['product']['draft'];
        $other['slug'] = 'another-product';
        $other['variants'][0]['sku'] = 'OTHER-FIXTURE';
        $this->postJson('/api/v1/operations/catalogue/products', ['draft' => $other])->assertUnprocessable();
    }

    public function test_guest_client_courier_and_staff_without_mfa_cannot_manage_catalogue(): void
    {
        $this->getJson('/api/v1/operations/catalogue/products')->assertUnauthorized();
        foreach (['client', 'courier', 'supplier'] as $role) {
            $this->staff($role);
            $this->getJson('/api/v1/operations/catalogue/products')->assertForbidden();
        }
        $user = $this->staff();
        $this->withSession(['mfa_verified' => false])
            ->getJson('/api/v1/operations/catalogue/products')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    public function test_catalogue_role_cannot_view_supplier_contacts_or_create_price_agreements(): void
    {
        $this->staff('catalogue');
        $this->getJson('/api/v1/operations/catalogue/suppliers')->assertForbidden();
        $this->postJson('/api/v1/operations/pricing/agreements', [])->assertForbidden();
        $this->getJson('/api/v1/operations/catalogue/lookups')->assertOk();
    }

    public function test_exact_scope_agreement_overlap_is_rejected_and_more_specific_rule_wins(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $this->agreement($context['supplier']['id']);
        $input = [
            'supplier_id' => $context['supplier']['id'],
            'category_id' => null,
            'product_id' => null,
            'minimum_quantity' => 1,
            'priority' => 0,
            'mode' => 'discount',
            'discount_basis_points' => 400,
            'fixed_cost' => null,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => null,
            'contract_reference' => 'OVERLAP-FIXTURE',
        ];
        $this->postJson('/api/v1/operations/pricing/agreements', $input)->assertUnprocessable();
        $specific = $this->agreement($context['supplier']['id'], [
            'product_id' => $context['product']['id'],
            'discount_basis_points' => 500,
        ]);
        $media = $this->image();
        $offer = $this->offer($context, $media['url']);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')
            ->assertJsonPath('data.agreement.id', $specific['id'])
            ->assertJsonPath('data.calculation.purchase_unit', 4750);
    }

    public function test_no_missing_agreement_or_falsified_supplier_funding_can_be_published(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $offer = $this->offer($context, $media['url'], null, false);
        $this->postJson('/api/v1/operations/catalogue/offers/'.$offer['id'].'/publish', [
            'version' => $offer['version'],
        ])->assertUnprocessable();
        $draft = $offer['draft'];
        $draft['promotion'] = [
            'price' => 4900,
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'kora_funding' => 0,
            'supplier_funding' => 100,
            'contract_reference' => null,
        ];
        $this->putJson('/api/v1/operations/catalogue/offers/'.$offer['id'], [
            'version' => $offer['version'],
            'draft' => $draft,
        ])->assertUnprocessable();
        $this->getJson('/api/v1/catalogue')->assertJsonCount(0, 'data');
    }

    public function test_supplier_cannot_be_archived_while_published_shops_depend_on_it(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $this->postJson('/api/v1/operations/catalogue/suppliers/'.$context['supplier']['id'].'/archive', [
            'version' => $context['supplier']['version'],
        ])->assertUnprocessable();
    }

    public function test_replacing_a_withdrawn_agreement_changes_new_quotes_without_rewriting_published_history(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $old = $this->agreement($context['supplier']['id']);
        $offer = $this->offer($context, $media['url']);
        $history = DB::table('catalogue_revisions')
            ->where('entity', 'offers')
            ->where('resource_id', $offer['id'])
            ->where('action', 'publish')
            ->value('snapshot');
        $this->postJson('/api/v1/operations/pricing/agreements/'.$old['id'].'/withdraw')
            ->assertOk()
            ->assertJsonPath('data.id', $old['id']);
        $this->postJson('/api/v1/operations/pricing/agreements/'.$old['id'].'/withdraw')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'agreement.withdrawn')->count());
        $this->getJson(
            '/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1',
        )->assertUnprocessable();
        $new = $this->agreement($context['supplier']['id'], [
            'discount_basis_points' => 500,
            'contract_reference' => 'RENEWED-FIXTURE',
        ]);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')
            ->assertJsonPath('data.agreement.id', $new['id'])
            ->assertJsonPath('data.calculation.purchase_unit', 4750);
        $this->assertSame(
            $history,
            DB::table('catalogue_revisions')
                ->where('entity', 'offers')
                ->where('resource_id', $offer['id'])
                ->where('action', 'publish')
                ->value('snapshot'),
        );
        $this->assertSame(4850, json_decode($history, true)['commercial']['calculation']['purchase_unit']);
        $this->getJson('/api/v1/operations/pricing/agreements')->assertJsonCount(2, 'data');
    }

    public function test_quantity_tiers_fixed_cost_and_dated_agreements_are_resolved_exactly(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $this->agreement($context['supplier']['id']);
        $tier = $this->agreement($context['supplier']['id'], [
            'minimum_quantity' => 10,
            'mode' => 'fixed',
            'fixed_cost' => 4701,
        ]);
        $offer = $this->offer($context, $media['url']);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=9')->assertJsonPath(
            'data.calculation.purchase_total',
            '43650',
        );
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=10')
            ->assertJsonPath('data.agreement.id', $tier['id'])
            ->assertJsonPath('data.calculation.purchase_unit', 4701)
            ->assertJsonPath('data.calculation.purchase_total', '47010');
        $future = $this->agreement($context['supplier']['id'], [
            'product_id' => $context['product']['id'],
            'discount_basis_points' => 600,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDays(2)->toIso8601String(),
        ]);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')->assertJsonPath(
            'data.calculation.purchase_unit',
            4850,
        );
        $this->travel(25)->hours();
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')
            ->assertJsonPath('data.agreement.id', $future['id'])
            ->assertJsonPath('data.calculation.purchase_unit', 4700);
        $this->travel(2)->days();
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')->assertJsonPath(
            'data.calculation.purchase_unit',
            4850,
        );
    }

    public function test_ambiguous_legacy_rules_fail_closed_and_catalogue_staff_cannot_withdraw_agreements(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $rule = $this->agreement($context['supplier']['id']);
        $offer = $this->offer($context, $media['url']);
        $duplicate = (array) DB::table('purchase_agreements')->find($rule['id']);
        $duplicate['id'] = (string) Str::uuid();
        DB::table('purchase_agreements')->insert($duplicate);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['agreement']]]);
        $this->staff('catalogue');
        $this->postJson('/api/v1/operations/pricing/agreements/'.$rule['id'].'/withdraw')->assertForbidden();
        $this->assertNull(DB::table('purchase_agreements')->find($rule['id'])->withdrawn_at);
    }

    public function test_future_promotions_use_future_agreements_and_negative_margin_requires_an_audited_owner_override(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $this->agreement($context['supplier']['id']);
        $promotion = [
            'price' => 4800,
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'kora_funding' => 200,
            'supplier_funding' => 0,
            'contract_reference' => null,
            'negative_margin_reason' => 'Budget de test autorisé',
        ];
        $offer = $this->offer($context, $media['url'], $promotion, false);
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=1')
            ->assertJsonPath('data.calculation.selling_unit', 5000)
            ->assertJsonPath('data.scheduled_promotion.calculation.selling_unit', 4800)
            ->assertJsonPath('data.scheduled_promotion.negative_margin', true);
        $this->staff('catalogue');
        $this->postJson('/api/v1/operations/catalogue/offers/'.$offer['id'].'/publish', [
            'version' => $offer['version'],
        ])->assertUnprocessable();
        $this->staff();
        $this->postJson('/api/v1/operations/catalogue/offers/'.$offer['id'].'/publish', [
            'version' => $offer['version'],
        ])->assertOk();
        $snapshot = json_decode(
            DB::table('catalogue_revisions')->where('entity', 'offers')->where('action', 'publish')->value('snapshot'),
            true,
        );
        $this->assertSame('Budget de test autorisé', $snapshot['draft']['promotion']['negative_margin_reason']);
        $this->assertSame('-50', $snapshot['commercial']['scheduled_promotion']['calculation']['gross_difference']);
        $this->getJson('/api/v1/catalogue/'.$offer['id'])->assertJsonPath('data.price.amount', 5000);
        $this->travel(70)->minutes();
        $this->getJson('/api/v1/catalogue/'.$offer['id'])->assertJsonPath('data.price.amount', 4800);
    }

    public function test_inherited_boolean_and_decimal_filters_work_for_child_categories_and_preserve_false(): void
    {
        $this->staff();
        $definition = [
            'key' => 'certified',
            'type' => 'boolean',
            'label' => ['fr' => 'Certifié', 'en' => 'Certified'],
            'unit' => null,
            'required' => true,
            'filterable' => true,
            'options' => [],
        ];
        $decimal = [
            'key' => 'length',
            'type' => 'number',
            'label' => ['fr' => 'Longueur', 'en' => 'Length'],
            'unit' => 'cm',
            'required' => true,
            'filterable' => true,
            'options' => [],
        ];
        $context = $this->catalogue([$definition, $decimal], ['certified' => false, 'length' => '012.5000']);
        $parent = $this->create('categories', [
            'parent_id' => null,
            'slug' => 'parent-fixture',
            'name' => ['fr' => 'Parent', 'en' => 'Parent'],
            'attributes' => [],
        ]);
        $draft = $context['category']['draft'];
        $draft['parent_id'] = $parent['id'];
        $saved = $this->putJson('/api/v1/operations/catalogue/categories/'.$context['category']['id'], [
            'version' => $context['category']['version'],
            'draft' => $draft,
        ])
            ->assertOk()
            ->json('data');
        $this->postJson('/api/v1/operations/catalogue/categories/'.$saved['id'].'/publish', [
            'version' => $saved['version'],
        ])->assertOk();
        $media = $this->image();
        $this->agreement($context['supplier']['id']);
        $offer = $this->offer($context, $media['url']);
        $this->getJson('/api/v1/catalogue?category=fixture-category&filters[certified]=false&filters[length]=12.5')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.certified', false)
            ->assertJsonPath('data.0.attributes.length', '12.5');
        $this->getJson('/api/v1/catalogue?category=fixture-category&filters[certified]=true')->assertJsonCount(
            0,
            'data',
        );
        $this->getJson('/api/v1/catalogue?category=parent-fixture')->assertJsonCount(1, 'data');
    }

    public function test_large_quote_totals_are_decimal_strings_so_browser_json_cannot_round_them(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $media = $this->image();
        $this->agreement($context['supplier']['id'], ['mode' => 'fixed', 'fixed_cost' => 999999999999]);
        $offer = $this->offer($context, $media['url'], null, false);
        $draft = $offer['draft'];
        $draft['reference_price'] = 1000000000000;
        $this->putJson('/api/v1/operations/catalogue/offers/'.$offer['id'], [
            'version' => $offer['version'],
            'draft' => $draft,
        ])->assertOk();
        $this->getJson('/api/v1/operations/pricing/offers/'.$offer['id'].'/preview?quantity=999999')
            ->assertOk()
            ->assertJsonPath('data.calculation.purchase_total', '999998999999000001')
            ->assertJsonPath('data.calculation.selling_total', '999999000000000000')
            ->assertJsonPath('data.calculation.gross_difference', '999999');
    }

    public function test_archiving_a_shop_does_not_allow_replacing_its_historical_supplier(): void
    {
        $this->staff();
        $context = $this->catalogue();
        $shop = $context['shop'];
        $other = $this->create('suppliers', ['name' => 'Other fixture', 'private_details' => ['email' => null]]);
        $archived = $this->postJson('/api/v1/operations/catalogue/shops/'.$shop['id'].'/archive', [
            'version' => $shop['version'],
        ])
            ->assertOk()
            ->json('data');
        $draft = $archived['draft'];
        $draft['supplier_id'] = $other['id'];
        $saved = $this->putJson('/api/v1/operations/catalogue/shops/'.$shop['id'], [
            'version' => $archived['version'],
            'draft' => $draft,
        ])
            ->assertOk()
            ->json('data');
        $this->postJson('/api/v1/operations/catalogue/shops/'.$shop['id'].'/publish', [
            'version' => $saved['version'],
        ])->assertUnprocessable();
        $this->assertSame($context['supplier']['id'], DB::table('shops')->find($shop['id'])->supplier_id);
    }
}
