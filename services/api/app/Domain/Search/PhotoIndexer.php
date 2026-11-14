<?php

namespace App\Domain\Search;

use App\Domain\Ai\AiGate;
use App\Domain\Ai\AiUnavailable;
use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Media\MediaLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Visual and text vectors of public offers. Publication, translation, edition or archiving only queues the
 * offer (cheap, inside the catalogue transaction); a scheduled worker calls the provider later, so neither a
 * customer page nor a publication waits for the AI. Bounded retries with growing delays.
 */
final class PhotoIndexer
{
    public const MAX_ATTEMPTS = 5;

    public function changed(string $entity, string $id): void
    {
        $q = DB::table('offers')
            ->join('variants', 'variants.id', '=', 'offers.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id');
        match ($entity) {
            'offers' => $q->where('offers.id', $id),
            'products' => $q->where('products.id', $id),
            'shops' => $q->where('offers.shop_id', $id),
            'suppliers' => $q->join('shops', 'shops.id', '=', 'offers.shop_id')->where('shops.supplier_id', $id),
            'categories' => null,
        };
        $this->queue($q->pluck('offers.id')->all(), $entity);
    }

    /** A media made public or withdrawn changes what the offers using it can be matched on. */
    public function media(string $mediaId): void
    {
        $this->queue(
            DB::table('offers')
                ->where('image_path', app(MediaLibrary::class)->publicPath($mediaId))
                ->pluck('id')
                ->all(),
            'media',
        );
    }

    public function queue(array $offerIds, string $reason): int
    {
        foreach (array_chunk(array_values(array_unique($offerIds)), 200) as $chunk) {
            DB::table('embedding_queue')->upsert(
                array_map(
                    fn ($id) => [
                        'offer_id' => $id,
                        'reason' => substr($reason, 0, 20),
                        'attempts' => 0,
                        'available_at' => now(),
                        'last_error' => null,
                        'failed_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    $chunk,
                ),
                ['offer_id'],
                ['reason', 'attempts', 'available_at', 'last_error', 'failed_at', 'updated_at'],
            );
        }

        return count($offerIds);
    }

    /** Full reindexing procedure: every offer is queued again; pages keep using the current vectors meanwhile. */
    public function reindexAll(): int
    {
        $count = 0;
        DB::table('offers')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$count) {
                $count += $this->queue($rows->pluck('id')->all(), 'reindex');
            });

        return $count;
    }

    public function process(int $limit): array
    {
        $done = ['indexed' => 0, 'removed' => 0, 'unchanged' => 0, 'failed' => 0, 'stopped' => null];
        if (! app(AiGate::class)->ready('embeddings')) {
            return [...$done, 'stopped' => 'not_configured'];
        }
        $rows = DB::table('embedding_queue')
            ->whereNull('failed_at')
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->orderBy('offer_id')
            ->limit($limit)
            ->get();
        foreach ($rows as $row) {
            try {
                $done[$this->offer($row->offer_id)]++;
                DB::table('embedding_queue')
                    ->where('offer_id', $row->offer_id)
                    ->where('updated_at', $row->updated_at)
                    ->delete();
            } catch (AiUnavailable $e) {
                if (in_array($e->reason, ['not_configured', 'budget'], true)) {
                    // Nothing more can be done this run; the queue waits untouched.
                    return [...$done, 'stopped' => $e->reason];
                }
                $attempts = $row->attempts + 1;
                DB::table('embedding_queue')
                    ->where('offer_id', $row->offer_id)
                    ->update([
                        'attempts' => $attempts,
                        'last_error' => $e->reason,
                        'available_at' => now()->addMinutes(2 ** $attempts),
                        'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
                        'updated_at' => now(),
                    ]);
                $done['failed']++;
            }
        }

        return $done;
    }

    public function offer(string $id): string
    {
        $row = app(PublicCatalogue::class)
            ->query()
            ->where('offers.id', $id)
            ->addSelect('products.category_id')
            ->first();
        if (! $row) {
            DB::table('offer_embeddings')->where('offer_id', $id)->delete();

            return 'removed';
        }
        $gate = app(AiGate::class);
        $model = $gate->embeddings()->model();
        $sources = ['text' => ['text' => $this->text($row)]];
        $image = $this->image($row->image_path);
        if ($image !== null) {
            $sources['image'] = ['image' => $image];
        } else {
            DB::table('offer_embeddings')->where('offer_id', $id)->where('kind', 'image')->delete();
        }
        $current = DB::table('offer_embeddings')->where('offer_id', $id)->pluck('source_hash', 'kind');
        $hashes = array_map(
            fn ($input) => hash('sha256', $model.'|'.($input['text'] ?? hash('sha256', $input['image']))),
            $sources,
        );
        $todo = array_filter(
            $sources,
            fn ($input, $kind) => ($current[$kind] ?? null) !== $hashes[$kind],
            ARRAY_FILTER_USE_BOTH,
        );
        if (! $todo) {
            return 'unchanged';
        }
        $result = $gate->embed('photo_index', null, array_values($todo), 'document');
        foreach (array_keys($todo) as $i => $kind) {
            DB::table('offer_embeddings')->upsert(
                [
                    [
                        'offer_id' => $id,
                        'kind' => $kind,
                        'model' => $model,
                        'dimensions' => count($result['vectors'][$i]),
                        'vector' => Vectors::encode($result['vectors'][$i]),
                        'source_hash' => $hashes[$kind],
                        'indexed_at' => now(),
                    ],
                ],
                ['offer_id', 'kind'],
                ['model', 'dimensions', 'vector', 'source_hash', 'indexed_at'],
            );
        }

        return 'indexed';
    }

    /** Published facts only: names, shop, categories, description start. No price, stock or private data. */
    private function text(object $row): string
    {
        $name = json_decode($row->name, true);
        $description = json_decode($row->description, true);
        $categories = array_map(
            fn ($c) => implode(' / ', json_decode($c->name, true)),
            app(CategoryAttributes::class)->ancestry($row->category_id),
        );

        return mb_substr(
            implode(
                "\n",
                array_filter([
                    ($name['fr'] ?? '').' / '.($name['en'] ?? ''),
                    $row->shop_name,
                    implode(' > ', array_reverse($categories)),
                    mb_substr($description['fr'] ?? '', 0, 400),
                    mb_substr($description['en'] ?? '', 0, 400),
                ]),
            ),
            0,
            2000,
        );
    }

    private function image(?string $path): ?string
    {
        $library = app(MediaLibrary::class);
        if (! $library->approvedPath($path) || ! preg_match('~/media/([a-f0-9-]{36})/public$~', (string) $path, $m)) {
            return null;
        }
        $media = DB::table('media_assets')->where('id', $m[1])->first();
        try {
            $bytes = Storage::disk($media->disk)->get($media->object_key);
        } catch (\Throwable) {
            return null;
        }

        return $bytes && hash_equals($media->sha256, hash('sha256', $bytes)) ? $bytes : null;
    }

    public function status(): array
    {
        return [
            'ready' => app(AiGate::class)->ready('embeddings'),
            'model' => app(AiGate::class)->embeddings()->model(),
            'indexed_offers' => DB::table('offer_embeddings')->distinct()->count('offer_id'),
            'queued' => DB::table('embedding_queue')->whereNull('failed_at')->count(),
            'failed' => DB::table('embedding_queue')->whereNotNull('failed_at')->count(),
            'oldest_queued_at' => ($oldest = DB::table('embedding_queue')->whereNull('failed_at')->min('created_at'))
                ? CarbonImmutable::parse($oldest, 'UTC')->toIso8601String()
                : null,
        ];
    }
}
