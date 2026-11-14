<?php

namespace App\Console\Commands;

use App\Domain\Content\Cms;
use App\Domain\Media\MediaLibrary;
use App\Domain\Search\TextIndexer;
use App\Models\HeroScene;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local test data for the storefront: shops, categories, products, offers and home scenes read from a JSON
 * manifest, written as ordinary catalogue rows the back office can edit or delete. Every row belongs to the
 * supplier "Fournisseur démo KORA", so --fresh removes exactly that test set. Refused outside the local
 * environment and unless KORA_DEMO=true. An optional "home" list publishes the CMS home page (blocks whose images
 * reuse the products' media, all replaceable from the back office).
 */
final class DemoStorefront extends Command
{
    protected $signature = 'kora:demo-storefront {manifest : JSON file describing shops, products and scenes} {--fresh : Remove the previous test set first}';

    protected $description = 'Local only: load storefront test data (editable in the back office) from a manifest.';

    private const SUPPLIER = 'Fournisseur démo KORA';

    public function handle(MediaLibrary $media): int
    {
        if (! app()->environment('local') || ! config('kora.demo')) {
            $this->error('Refused: local environment with KORA_DEMO=true only.');

            return self::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
        $base = dirname(realpath($this->argument('manifest')));
        if ($this->option('fresh')) {
            $this->clear();
        } elseif (DB::table('suppliers')->where('name', self::SUPPLIER)->exists()) {
            $this->error('A test set exists: run again with --fresh to replace it.');

            return self::FAILURE;
        }
        $actor = User::firstOrCreate(['email' => 'demo-content@kora.local'], ['name' => 'Contenu de test', 'password' => Str::random(40)]);
        $now = now();
        $supplier = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $supplier, 'name' => self::SUPPLIER, 'private_details' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $categories = [];
        foreach ($manifest['categories'] as $c) {
            $categories[$c['slug']] = DB::table('categories')->where('slug', $c['slug'])->value('id') ?? (string) Str::uuid();
            DB::table('categories')->updateOrInsert(['id' => $categories[$c['slug']]], [
                'slug' => $c['slug'],
                'name' => json_encode($c['name']),
                'attribute_definitions' => json_encode($c['attributes'] ?? []),
                'published' => true,
                'status' => 'published',
                'published_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $offers = [];
        $shopIds = [];
        foreach ($manifest['shops'] as $s) {
            $shop = (string) Str::uuid();
            DB::table('shops')->insert([
                'id' => $shop,
                'supplier_id' => $supplier,
                'slug' => $s['slug'],
                'name' => $s['name'],
                'description' => json_encode($s['description']),
                'city' => $s['city'] ?? 'Douala',
                'district' => $s['district'] ?? null,
                'published' => true,
                'status' => 'published',
                'published_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($s['products'] as $p) {
                $asset = $media->upload(new UploadedFile($base.'/'.$p['image'], basename($p['image']), null, null, true), $p['alt'], $actor);
                DB::table('media_assets')->where('id', $asset['id'])->update(['is_public' => true, 'rights_confirmed_at' => $now]);
                $product = (string) Str::uuid();
                $variant = (string) Str::uuid();
                $offer = (string) Str::uuid();
                DB::table('products')->insert([
                    'id' => $product,
                    'category_id' => $categories[$p['category']],
                    'slug' => $p['slug'],
                    'name' => json_encode($p['name']),
                    'description' => json_encode($p['description']),
                    'status' => 'published',
                    'published_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('variants')->insert(['id' => $variant, 'product_id' => $product, 'sku' => 'TEST-'.strtoupper($p['slug']), 'attributes' => json_encode((object) ($p['attributes'] ?? [])), 'created_at' => $now, 'updated_at' => $now]);
                DB::table('offers')->insert([
                    'id' => $offer,
                    'variant_id' => $variant,
                    'shop_id' => $shop,
                    'reference_price' => $p['price'],
                    'selling_price' => $p['price'],
                    'promotion_price' => $p['promotion_price'] ?? null,
                    'promotion_starts_at' => isset($p['promotion_price']) ? $now->copy()->subDay() : null,
                    'promotion_ends_at' => isset($p['promotion_price']) ? $now->copy()->addDays(30) : null,
                    'status' => 'published',
                    'availability' => $p['availability'] ?? 'available',
                    'warranty' => json_encode($p['warranty'] ?? ['fr' => 'Garantie boutique 6 mois', 'en' => '6-month shop warranty']),
                    'image_path' => $media->publicPath($asset['id']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $offers[$p['slug']] = ['id' => $offer, 'product' => $product, 'media_id' => $asset['id'], 'media' => $media->publicPath($asset['id']), 'alt' => $p['alt']];
                $shopIds[$s['slug']] = $shop;
            }
        }
        foreach ($manifest['scenes'] as $position => $sc) {
            $content = [
                'kind' => 'product',
                'target_id' => $offers[$sc['product']]['id'],
                'category' => null,
                'title' => $sc['title'],
                'description' => $sc['description'],
                'label' => $sc['label'],
                'cta' => $sc['cta'],
                'alt' => $offers[$sc['product']]['alt'],
                'media' => $offers[$sc['product']]['media'],
                'mobile_media' => null,
                'decor' => 'bands',
                'preset' => 'signature',
                'hold_ms' => 8000,
                'autoplay' => true,
                'starts_at' => null,
                'ends_at' => null,
                'priority' => 50 - $position,
            ];
            HeroScene::create(['name' => 'Test '.$sc['label']['fr'], 'draft' => $content, 'publication' => $content, 'paused' => false, 'position' => $position + 1, 'version' => 1, 'published_at' => $now]);
        }
        $pages = ['home' => $manifest['home'] ?? []] + ($manifest['pages'] ?? []);
        foreach ($pages as $pageSlug => $pageBlocks) {
            if (! $pageBlocks) {
                continue;
            }
            $blocks = [];
            foreach ($pageBlocks as $b) {
                $block = ['type' => $b['type']];
                if (isset($b['image_from'])) {
                    $block['media_id'] = $offers[$b['image_from']]['media_id'];
                } elseif (isset($b['image_file'])) {
                    $asset = $media->upload(new UploadedFile($base.'/'.$b['image_file'], basename($b['image_file']), null, null, true), $b['alt'] ?? ['fr' => '', 'en' => ''], $actor);
                    DB::table('media_assets')->where('id', $asset['id'])->update(['is_public' => true, 'rights_confirmed_at' => $now]);
                    $block['media_id'] = $asset['id'];
                }
                foreach (['title', 'text'] as $key) {
                    if (isset($b[$key])) {
                        $block[$key] = $b[$key];
                    }
                }
                if (isset($b['link_product'])) {
                    $block['link'] = '/products/'.$offers[$b['link_product']]['id'];
                } elseif (array_key_exists('link', $b)) {
                    $block['link'] = $b['link'];
                }
                if (isset($b['products'])) {
                    $block['product_ids'] = array_map(fn ($slug) => $offers[$slug]['product'], $b['products']);
                }
                if (isset($b['shops'])) {
                    $block['shop_ids'] = array_map(fn ($slug) => $shopIds[$slug], $b['shops']);
                }
                $blocks[] = $block;
            }
            $cms = app(Cms::class);
            $titles = ['home' => ['fr' => 'Accueil', 'en' => 'Home'], 'catalogue' => ['fr' => 'Catalogue', 'en' => 'Catalogue']];
            $draft = $cms->saveDraft('page', $pageSlug, ['title' => $titles[$pageSlug] ?? ['fr' => $pageSlug, 'en' => $pageSlug], 'content' => ['blocks' => $blocks]], $actor);
            $cms->publish($draft['id'], [], $actor);
        }
        if (! empty($manifest['flash'])) {
            // Flash sale: a CMS campaign on the home page (dates, title, products, shown/hidden from the back office),
            // with a real promotion on each offer for the same period.
            $f = $manifest['flash'];
            $ends = now()->addHours((int) ($f['hours'] ?? 48));
            foreach ($f['products'] as $slug => $promo) {
                DB::table('offers')->where('id', $offers[$slug]['id'])->update([
                    'promotion_price' => $promo,
                    'promotion_starts_at' => now()->subMinute(),
                    'promotion_ends_at' => $ends,
                ]);
            }
            $cms = app(Cms::class);
            $campaign = $cms->saveCampaign(null, [
                'name' => $f['name'],
                'starts_at' => now()->subMinute()->toIso8601String(),
                'ends_at' => $ends->toIso8601String(),
                'priority' => 10,
                'locales' => ['fr', 'en'],
                'pages' => ['home'],
                'theme' => [],
                'banner' => $f['banner'],
                'product_ids' => array_map(fn ($slug) => $offers[$slug]['product'], array_keys($f['products'])),
                'delivery_offer_id' => null,
            ], $actor);
            $cms->campaignStatus($campaign['id'], ['status' => 'active', 'version' => $campaign['version']], $actor);
        }
        app(TextIndexer::class)->rebuild();
        $this->info(json_encode(['shops' => count($manifest['shops']), 'products' => count($offers), 'scenes' => count($manifest['scenes'])]));

        return self::SUCCESS;
    }

    /** Removes the previous test set only: what belongs to the test supplier, and the test scenes. */
    private function clear(): void
    {
        DB::transaction(function () {
            $supplier = DB::table('suppliers')->where('name', self::SUPPLIER)->value('id');
            $shops = DB::table('shops')->where('supplier_id', $supplier)->pluck('id');
            $offers = DB::table('offers')->whereIn('shop_id', $shops)->get(['id', 'variant_id', 'image_path']);
            $variants = $offers->pluck('variant_id');
            $products = DB::table('variants')->whereIn('id', $variants)->pluck('product_id');
            $categories = DB::table('products')->whereIn('id', $products)->pluck('category_id')->unique();
            $scenes = HeroScene::query()->where('name', 'like', 'Test %')->orWhere('name', 'like', 'Démo %')->pluck('id');
            DB::table('hero_revisions')->whereIn('hero_scene_id', $scenes)->delete();
            DB::table('hero_scenes')->whereIn('id', $scenes)->delete();
            foreach (['catalogue_search_tokens', 'catalogue_search_documents', 'embedding_queue', 'offer_embeddings', 'supplier_availability'] as $table) {
                DB::table($table)->whereIn('offer_id', $offers->pluck('id'))->delete();
            }
            DB::table('offers')->whereIn('id', $offers->pluck('id'))->delete();
            DB::table('variants')->whereIn('id', $variants)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            DB::table('shops')->whereIn('id', $shops)->delete();
            DB::table('categories')->whereIn('id', $categories)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('products')->whereColumn('products.category_id', 'categories.id'))->delete();
            foreach ($offers as $o) {
                if (preg_match('~/media/([a-f0-9-]{36})/public$~', (string) $o->image_path, $m) && ($asset = DB::table('media_assets')->where('id', $m[1])->first())) {
                    Storage::disk($asset->disk)->delete($asset->object_key);
                    DB::table('media_assets')->where('id', $asset->id)->delete();
                }
            }
            DB::table('suppliers')->where('id', $supplier)->delete();
            // CMS versions are immutable history: the home page simply gets a new published version.
            $actor = DB::table('users')->where('email', 'demo-content@kora.local')->value('id');
            if ($actor) {
                // Test campaigns are paused, not deleted, so the audit trail stays complete.
                DB::table('cms_campaigns')->where('created_by', $actor)->where('status', 'active')
                    ->update(['status' => 'paused', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
        });
    }
}
