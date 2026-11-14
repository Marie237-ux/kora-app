<?php

namespace Tests\Feature;

use App\Domain\Search\TextIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class TextSearchTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    private function catalogue(): array
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update([
                'name' => json_encode(['fr' => 'Téléphone élégant', 'en' => 'Elegant handset']),
                'description' => json_encode(['fr' => 'Coque en aluminium', 'en' => 'Aluminium case']),
            ]);
        DB::table('categories')
            ->where('id', $ctx['category'])
            ->update(['name' => json_encode(['fr' => 'Électronique', 'en' => 'Electronics'])]);
        DB::table('shops')
            ->where('id', $ctx['shop'])
            ->update(['name' => 'Boutique Source Unique']);
        app(TextIndexer::class)->offer($ctx['offer']);

        return $ctx;
    }

    private function find(string $q, string $locale = 'fr', array $other = [])
    {
        return $this->getJson('/api/v1/catalogue?'.http_build_query(['q' => $q, 'locale' => $locale, ...$other]));
    }

    public function test_both_languages_accents_shop_category_description_and_sku_are_searchable(): void
    {
        $ctx = $this->catalogue();
        foreach (
            ['TELEPHONE', 'téléphone', 'handset', 'electronique', 'electronics', 'aluminium', 'source unique'] as $q
        ) {
            $this->find($q, 'en')
                ->assertOk()
                ->assertJsonPath('data.0.id', $ctx['offer'])
                ->assertJsonPath('meta.total', 1);
        }
        $sku = DB::table('variants')->where('id', $ctx['variant'])->value('sku');
        $this->find($sku)->assertOk()->assertJsonPath('data.0.id', $ctx['offer']);
        $this->find('telephone aluminium')->assertJsonPath('meta.total', 1);
        $this->find('telephone nonexistentword')->assertJsonPath('meta.total', 0);
    }

    public function test_prefix_suggestions_and_bounded_typos_return_existing_public_offers_only(): void
    {
        $ctx = $this->catalogue();
        $this->find('telephne')->assertOk()->assertJsonPath('data.0.id', $ctx['offer']);
        $this->getJson('/api/v1/catalogue/suggestions?q=tele')->assertOk()->assertJsonPath('data.0.id', $ctx['offer']);
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        // Publication guard is checked live even with an intentionally stale index.
        $this->find('telephone')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/catalogue/suggestions?q=tele')->assertJsonCount(0, 'data');
    }

    public function test_synonyms_are_versioned_normalized_replayed_and_revocable_without_reindexing(): void
    {
        $this->catalogue();
        $key = $this->fixtureId();
        $body = ['locale' => 'fr', 'terms' => ['téléphone', 'mobile'], 'active' => true];
        $r = $this->op('POST', 'search/synonyms', $body, $key)
            ->assertCreated()
            ->assertJsonPath('data.terms.0', 'telephone')
            ->json('data');
        $this->op('POST', 'search/synonyms', $body, $key)->assertCreated()->assertJsonPath('data.id', $r['id']);
        $this->find('mobile')->assertJsonPath('meta.total', 1);
        $this->op('PUT', 'search/synonyms/'.$r['id'], [
            'version' => 1,
            'locale' => 'fr',
            'terms' => ['telephone', 'mobile'],
            'active' => false,
        ])->assertOk();
        $this->find('mobile')->assertJsonPath('meta.total', 0);
        $this->op('PUT', 'search/synonyms/'.$r['id'], [
            'version' => 1,
            'locale' => 'fr',
            'terms' => ['telephone', 'mobile'],
            'active' => true,
        ])->assertConflict();
        $this->op('POST', 'search/synonyms', [
            'locale' => 'fr',
            'terms' => ['un mot', 'autre'],
            'active' => true,
        ])->assertUnprocessable();
    }

    public function test_misses_keep_only_daily_keyed_aggregates_and_filter_sort_pagination_still_work(): void
    {
        $ctx = $this->catalogue();
        $private = 'privatename@example.test';
        $this->find($private)->assertJsonPath('meta.total', 0);
        $this->find($private)->assertJsonPath('meta.total', 0);
        $row = DB::table('search_misses')->first();
        $this->assertSame(2, (int) $row->occurrences);
        $this->assertStringNotContainsString('privatename', json_encode($row));
        $this->assertSame(64, strlen($row->query_digest));
        $this->find('handset', 'fr', [
            'shop' => $ctx['shop'],
            'category' => $ctx['category'],
            'sort' => 'price_desc',
        ])->assertJsonPath('meta.total', 1);
        $this->find('handset', 'fr', ['shop' => 'unknown'])->assertJsonPath('meta.total', 0);
        $this->find('one two three four five six seven eight nine')->assertUnprocessable();
        $this->operationUser('support');
        $this->op('GET', 'search/misses')->assertForbidden();
        $this->travel(31)->days();
        $this->artisan('search:cleanup-misses')->assertSuccessful();
        $this->assertDatabaseCount('search_misses', 0);
    }

    public function test_rebuild_updates_translation_removes_archived_rows_and_keeps_private_supplier_data_out(): void
    {
        $ctx = $this->catalogue();
        DB::table('suppliers')
            ->where('id', $ctx['supplier'])
            ->update(['private_details' => json_encode(['notes' => 'privatesuppliernegotiation'])]);
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update(['name' => json_encode(['fr' => 'Tablette vérifiée', 'en' => 'Verified tablet'])]);
        $this->artisan('search:reindex-text')->assertSuccessful();
        $this->find('tablette')->assertJsonPath('meta.total', 1);
        $this->find('handset')->assertJsonPath('meta.total', 0);
        $this->find('privatesuppliernegotiation')->assertJsonPath('meta.total', 0);
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        $this->artisan('search:reindex-text')->assertSuccessful();
        $this->assertDatabaseCount('catalogue_search_documents', 0);
        $this->assertDatabaseCount('catalogue_search_tokens', 0);
    }

    public function test_normal_publication_updates_search_atomically_and_archive_removes_its_index(): void
    {
        $ctx = $this->catalogue();
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update(['name' => json_encode(['fr' => 'Tablette', 'en' => 'Tablet'])]);
        $this->postJson('/api/v1/operations/catalogue/products/'.$ctx['product'].'/publish', [
            'version' => 1,
        ])->assertOk();
        $this->find('tablette')->assertJsonPath('meta.total', 1);
        $this->find('handset')->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/operations/catalogue/offers/'.$ctx['offer'].'/archive', [
            'version' => 1,
        ])->assertOk();
        $this->assertDatabaseCount('catalogue_search_tokens', 0);
        $this->find('tablette')->assertJsonPath('meta.total', 0);
    }

    public function test_choice_attributes_are_searchable_in_both_languages_with_combined_typed_filters(): void
    {
        $ctx = $this->catalogue();
        $definition = [
            'key' => 'color',
            'label' => ['fr' => 'Couleur', 'en' => 'Color'],
            'type' => 'choice',
            'unit' => null,
            'required' => false,
            'filterable' => true,
            'options' => [['value' => 'blue', 'label' => ['fr' => 'Bleu', 'en' => 'Blue']]],
        ];
        DB::table('categories')
            ->where('id', $ctx['category'])
            ->update(['attribute_definitions' => json_encode([$definition])]);
        DB::table('variants')
            ->where('id', $ctx['variant'])
            ->update(['attributes' => json_encode(['color' => 'blue'])]);
        app(TextIndexer::class)->offer($ctx['offer']);
        $this->find('bleu', 'en', ['category' => $ctx['category'], 'filters' => ['color' => 'blue']])->assertJsonPath(
            'meta.total',
            1,
        );
        $this->find('blue', 'fr')->assertJsonPath('meta.total', 1);
    }
}
