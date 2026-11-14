<?php

namespace Tests\Feature;

use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\AnthropicGenerator;
use App\Domain\Ai\VoyageEmbeddings;
use App\Domain\Search\PhotoIndexer;
use App\Domain\Search\TextIndexer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class PhotoSearchTest extends TestCase
{
    use AiFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Africa/Douala'));
    }

    private function guest(?string $owner = null): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withSession($owner ? ['kora_anonymous_owner' => $owner] : [])->withHeaders([
            'Origin' => 'http://localhost:4321',
            'Accept' => 'application/json',
        ]);
    }

    private function search(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/v1/search/photo', ['file' => $file, ...$extra]);
    }

    public function test_photo_search_answers_with_public_offers_and_honest_levels(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $red = $this->colourOffer($ctx, [255, 0, 0], name: 'Sac rouge');
        $darkRed = $this->colourOffer($ctx, [200, 40, 40], name: 'Sac bordeaux');
        app(TextIndexer::class)->rebuild();
        $this->guest();

        // Without a provider key nothing is sent anywhere and the customer keeps the other paths.
        $none = $this->search($this->photo(255, 0, 0))->assertCreated()->json('data');
        $this->assertSame('unavailable', $none['status']);
        $this->assertSame('not_configured', $none['unavailable_reason']);
        $this->assertSame([], $none['results']);
        $this->assertTrue($none['next']['request_product']);
        $this->assertSame(
            ['indexed' => 0, 'removed' => 0, 'unchanged' => 0, 'failed' => 0, 'stopped' => 'not_configured'],
            app(PhotoIndexer::class)->process(10),
        );

        $this->useFakeAi();
        $this->artisan('search:index-photos', ['--all' => true, '--limit' => 50])->assertSuccessful();
        // Three public offers, one provider call each (text and image together); the first has no photo.
        $this->assertSame(2, DB::table('offer_embeddings')->where('kind', 'image')->count());
        $this->assertSame(3, DB::table('offer_embeddings')->where('kind', 'text')->count());
        $this->assertSame(0, DB::table('embedding_queue')->count());
        $this->assertSame(3, $this->embeddings->calls);
        // Unchanged sources are not sent twice.
        app(PhotoIndexer::class)->reindexAll();
        $this->artisan('search:index-photos')->assertSuccessful();
        $this->assertSame(3, $this->embeddings->calls);

        // Clear lead over the next one: "match" for the first only, the other is "similar"; scores are given.
        $match = $this->search($this->photo(255, 0, 0))->assertCreated()->json('data');
        $this->assertSame('completed', $match['status']);
        $this->assertSame('match', $match['level']);
        $this->assertSame(
            [$red['offer'], $darkRed['offer']],
            array_column(array_column($match['results'], 'offer'), 'id'),
        );
        $this->assertSame(['match', 'similar'], array_column($match['results'], 'label'));
        $this->assertGreaterThan($match['results'][1]['score'], $match['results'][0]['score']);
        $this->assertSame(1000, $match['results'][0]['offer']['price']['amount']);

        // Close candidates: only "similar", never a claimed identification.
        $similar = $this->search($this->photo(255, 0, 255))->assertCreated()->json('data');
        $this->assertSame('similar', $similar['level']);
        $this->assertSame(['similar', 'similar'], array_column($similar['results'], 'label'));

        // Nothing close: no result and the product request path.
        $nothing = $this->search($this->photo(0, 255, 0))->assertCreated()->json('data');
        $this->assertSame('none', $nothing['level']);
        $this->assertSame([], $nothing['results']);
        $this->assertTrue($nothing['next']['request_product']);

        // Refining with filters reuses the stored vector, without a new provider call.
        $calls = $this->embeddings->calls;
        $this->getJson('/api/v1/search/photo/'.$match['id'].'?category=unknown-category')
            ->assertOk()
            ->assertJsonPath('data.level', 'none');
        $this->getJson('/api/v1/search/photo/'.$match['id'].'?q=bordeaux')
            ->assertOk()
            ->assertJsonPath('data.results.0.offer.id', $darkRed['offer']);
        $this->assertSame($calls, $this->embeddings->calls);

        // An archived offer disappears at once, even before its vector is removed.
        DB::table('offers')
            ->where('id', $red['offer'])
            ->update(['status' => 'archived']);
        $after = $this->getJson('/api/v1/search/photo/'.$match['id'])->json('data');
        $this->assertNotContains($red['offer'], array_column(array_column($after['results'], 'offer'), 'id'));
        app(PhotoIndexer::class)->changed('offers', $red['offer']);
        app(PhotoIndexer::class)->process(10);
        $this->assertSame(0, DB::table('offer_embeddings')->where('offer_id', $red['offer'])->count());

        // Another session or account never sees this photo or its search.
        $this->getJson('/api/v1/search/photo/'.$match['id'].'/image')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->guest(str_repeat('b', 64));
        $this->getJson('/api/v1/search/photo/'.$match['id'])->assertNotFound();
        $this->getJson('/api/v1/search/photo/'.$match['id'].'/image')->assertNotFound();
    }

    public function test_images_are_checked_and_limited_and_the_service_degrades_safely(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $this->colourOffer($ctx, [255, 0, 0]);
        $this->useFakeAi();
        $this->guest();

        // Not an image, an image disguised by its name, or too heavy: refused without processing.
        $this->search(UploadedFile::fake()->createWithContent('photo.png', '<?php echo "x";'))->assertUnprocessable();
        $this->search(UploadedFile::fake()->createWithContent('photo.jpg', "%PDF-1.4\n%%EOF"))->assertUnprocessable();
        $this->search(UploadedFile::fake()->create('big.png', 9 * 1024, 'image/png'))->assertUnprocessable();
        $this->assertSame(0, DB::table('photo_searches')->count());
        $this->assertSame(0, $this->embeddings->calls);

        // Large phone photo: accepted, reduced to 1024 px WebP, metadata dropped by re-encoding.
        $big = $this->search(UploadedFile::fake()->createWithContent('phone.png', $this->png(255, 0, 0, 3000)))
            ->assertCreated()
            ->json('data');
        $this->assertSame(['width' => 1024, 'height' => 1024], $big['image']);
        $stored = DB::table('photo_searches')->where('id', $big['id'])->first();
        $this->assertSame('RIFF', substr(Storage::disk('local')->get($stored->object_key), 0, 4));

        // Provider failure or budget reached: "unavailable", journaled without content, nothing else blocked.
        $this->embeddings->fail = 'provider_unreachable';
        $this->search($this->photo(255, 0, 0))
            ->assertCreated()
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.unavailable_reason', 'provider_unreachable');
        $this->embeddings->fail = null;
        DB::table('ai_usage')->insert([
            'id' => $this->fixtureId(),
            'feature' => 'photo_index',
            'provider' => 'fake',
            'status' => 'ok',
            'estimated_cost_micro_usd' => 5_000_000,
            'created_at' => now(),
        ]);
        $this->search($this->photo(255, 0, 0))->assertCreated()->assertJsonPath('data.unavailable_reason', 'budget');
        $this->assertSame(
            ['failed', 'ok', 'refused'],
            DB::table('ai_usage')
                ->where('feature', 'photo_search')
                ->orderBy('status')
                ->distinct()
                ->pluck('status')
                ->all(),
        );
        $this->assertSame(
            [
                'created_at',
                'duration_ms',
                'estimated_cost_micro_usd',
                'feature',
                'id',
                'input_units',
                'output_units',
                'provider',
                'reason',
                'status',
                'user_id',
            ],
            collect(array_keys((array) DB::table('ai_usage')->first()))->sort()->values()->all(),
        );

        // Daily limit per session.
        config(['kora.ai.photo_searches_per_day' => DB::table('photo_searches')->count()]);
        $this->search($this->photo(255, 0, 0))->assertStatus(429);

        // Searches expire with their private image.
        $this->travel(25)->hours();
        $this->artisan('search:purge-photos')->assertSuccessful();
        $this->assertSame(0, DB::table('photo_searches')->count());
        $this->assertFalse(Storage::disk('local')->exists($stored->object_key));
    }

    public function test_a_search_becomes_a_product_request_only_with_consent_and_without_retyping(): void
    {
        $this->operationUser();
        $this->fixtureCatalogue();
        $this->useFakeAi();
        $this->guest();
        $search = $this->search($this->photo(10, 20, 200), ['q' => 'lampe'])
            ->assertCreated()
            ->json('data');
        $body = ['locale' => 'fr', 'details' => ['name' => 'Lampe bleue', 'description' => 'Vue sur une photo.']];
        $this->postJson('/api/v1/search/photo/'.$search['id'].'/product-request', $body)->assertUnprocessable();
        $request = $this->postJson('/api/v1/search/photo/'.$search['id'].'/product-request', [
            ...$body,
            'consent' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.details.name', 'Lampe bleue')
            ->assertJsonCount(1, 'data.photos')
            ->json('data');
        // Repeating returns the same draft; the request is not an order and the photo stays private.
        $this->postJson('/api/v1/search/photo/'.$search['id'].'/product-request', [...$body, 'consent' => true])
            ->assertCreated()
            ->assertJsonPath('data.id', $request['id']);
        $this->assertSame(1, DB::table('product_requests')->count());
        $this->assertSame(0, DB::table('orders')->count());
        $photo = DB::table('product_request_photos')->first();
        $this->assertSame(DB::table('photo_searches')->value('sha256'), $photo->sha256);
        $this->getJson('/api/v1/product-requests/'.$request['id'])->assertOk();
        $this->guest(str_repeat('c', 64));
        $this->postJson('/api/v1/search/photo/'.$search['id'].'/product-request', [
            ...$body,
            'consent' => true,
        ])->assertNotFound();
    }

    public function test_real_adapters_follow_the_provider_contracts_without_leaking_keys(): void
    {
        config([
            'services.voyage.key' => 'test-voyage-key',
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-test',
        ]);
        Http::fake([
            'api.voyageai.com/*' => Http::sequence()
                ->push([
                    'data' => [['index' => 1, 'embedding' => [0, 1]], ['index' => 0, 'embedding' => [1, 0]]],
                    'usage' => ['total_tokens' => 42],
                ])
                ->push(['error' => 'quota'], 429),
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        ['type' => 'text', 'text' => 'ignored'],
                        ['type' => 'tool_use', 'name' => 'product_proposal', 'input' => ['missing' => ['brand']]],
                    ],
                    'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
                ])
                ->push(['content' => [['type' => 'text', 'text' => 'no tool']]]),
        ]);
        $voyage = app(VoyageEmbeddings::class)->embed(
            [['image' => $this->png(1, 2, 3)], ['text' => 'Sac']],
            'document',
        );
        $this->assertSame([[1, 0], [0, 1]], $voyage['vectors']);
        $this->assertSame(42, $voyage['units']);
        $claude = app(AnthropicGenerator::class)->structured(
            'system',
            [['text' => 'faits']],
            'product_proposal',
            ['type' => 'object'],
            500,
        );
        $this->assertSame(['missing' => ['brand']], $claude['data']);
        $this->assertSame([120, 30], [$claude['input_units'], $claude['output_units']]);
        Http::assertSent(
            fn (ClientRequest $r) => str_contains($r->url(), 'voyageai.com/v1/multimodalembeddings') &&
                $r->hasHeader('Authorization', 'Bearer test-voyage-key') &&
                $r['input_type'] === 'document' &&
                $r['inputs'][0]['content'][0]['type'] === 'image_base64' &&
                str_starts_with($r['inputs'][0]['content'][0]['image_base64'], 'data:image/webp;base64,') &&
                $r['inputs'][1]['content'][0] === ['type' => 'text', 'text' => 'Sac'],
        );
        Http::assertSent(
            fn (ClientRequest $r) => str_contains($r->url(), 'api.anthropic.com/v1/messages') &&
                $r->hasHeader('x-api-key', 'test-anthropic-key') &&
                $r->hasHeader('anthropic-version', '2023-06-01') &&
                $r['model'] === 'claude-test' &&
                $r['tool_choice'] === ['type' => 'tool', 'name' => 'product_proposal'],
        );

        // Refusals and unusable answers become "unavailable", never a made-up result.
        try {
            app(VoyageEmbeddings::class)->embed([['text' => 'x']], 'query');
            $this->fail('A refused call must not return vectors.');
        } catch (AiUnavailable $e) {
            $this->assertSame('provider_rate_limited', $e->reason);
        }
        try {
            app(AnthropicGenerator::class)->structured(
                's',
                [['text' => 'x']],
                'product_proposal',
                ['type' => 'object'],
                100,
            );
            $this->fail('An answer without the tool call is unusable.');
        } catch (AiUnavailable $e) {
            $this->assertSame('invalid_output', $e->reason);
        }
    }
}
