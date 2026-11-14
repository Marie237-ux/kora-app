<?php

namespace App\Domain\Search;

use App\Domain\Ai\AiGate;
use App\Domain\Ai\AiUnavailable;
use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Media\ImageNormalizer;
use App\Domain\Procurement\Records;
use App\Domain\ProductRequests\ProductRequests;
use App\Domain\Shared\SessionIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Search by photo: the image is re-encoded on the server (orientation, size, metadata removed), compared with
 * the vectors of offers that are public right now, and answered with existing catalogue offers and their
 * similarity. "match" is only a strong, clearly leading similarity, never a certain identification. Without AI
 * the search answers "unavailable" and the customer keeps text search, filters and the product request.
 */
final class PhotoSearch
{
    public function search(Request $http, UploadedFile $file, array $filters): array
    {
        $user = $http->user();
        $owner = $user ? null : app(SessionIdentity::class)->hash($http);
        $today = DB::table('photo_searches')
            ->where($user ? 'user_id' : 'guest_owner_hash', $user ? $user->id : $owner)
            ->where('created_at', '>=', app(AiGate::class)->dayStart())
            ->count();
        abort_if($today >= config('kora.ai.photo_searches_per_day'), 429);
        // Phone photos: up to 8 MB and 12.6 megapixels, reduced to 1024 px WebP before anything else.
        $image = app(ImageNormalizer::class)->normalize($file, 8 * 1024 * 1024, 12_600_000, 1024);
        $vector = null;
        $model = null;
        $reason = null;
        $gate = app(AiGate::class);
        try {
            // Unit length, like the stored offer vectors, so the similarity stays between -1 and 1.
            $vector = Vectors::decode(
                Vectors::encode(
                    $gate->embed('photo_search', $user, [['image' => $image['contents']]], 'query')['vectors'][0],
                ),
            );
            $model = $gate->embeddings()->model();
        } catch (AiUnavailable $e) {
            $reason = $e->reason;
        }
        $id = Records::id();
        $disk = config('kora.document_disk');
        $key = 'photo-searches/'.$id.'.webp';
        try {
            $stored = Storage::disk($disk)->put($key, $image['contents'], [
                'visibility' => 'private',
                'ContentType' => 'image/webp',
            ]);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless($stored, 503);
        $ranked = $vector ? $this->rank($vector, $model, $filters) : ['level' => null, 'results' => []];
        try {
            DB::table('photo_searches')->insert([
                'id' => $id,
                'user_id' => $user?->id,
                'guest_owner_hash' => $owner,
                'disk' => $disk,
                'object_key' => $key,
                'sha256' => $image['sha256'],
                'width' => $image['width'],
                'height' => $image['height'],
                'bytes' => $image['bytes'],
                'query_vector' => $vector ? Vectors::encode($vector) : null,
                'model' => $model,
                'status' => $vector ? 'completed' : 'unavailable',
                'level' => $ranked['level'],
                'results' => Records::json(
                    array_map(fn ($r) => ['offer_id' => $r['offer']['id'], 'score' => $r['score']], $ranked['results']),
                ),
                'expires_at' => now()->addHours(config('kora.ai.photo_search_hours')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($key);

            throw $e;
        }

        return $this->dto(Records::row('photo_searches', $id, false), $ranked, $reason);
    }

    /** Refine with words or a category without sending the photo again; results are re-read as public now. */
    public function show(Request $http, string $id, array $filters): array
    {
        $search = $this->owned($http, $id);
        $ranked = $search->query_vector
            ? $this->rank(Vectors::decode($search->query_vector), $search->model, $filters)
            : ['level' => null, 'results' => []];

        return $this->dto($search, $ranked, $search->query_vector ? null : 'not_configured');
    }

    public function file(Request $http, string $id)
    {
        $search = $this->owned($http, $id);
        try {
            $contents = Storage::disk($search->disk)->get($search->object_key);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless($contents && hash_equals($search->sha256, hash('sha256', $contents)), 503);

        return response($contents, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }

    /**
     * With the customer's agreement, the photo and the words of the search start a product request draft,
     * so nothing has to be entered twice. The photo stays private; the request is not an order.
     */
    public function toRequest(Request $http, string $id, array $data): array
    {
        $search = $this->owned($http, $id);
        $requests = app(ProductRequests::class);
        if (
            $search->product_request_id &&
            ($existing = DB::table('product_requests')->where('id', $search->product_request_id)->first())
        ) {
            return $requests->dto($existing);
        }
        $contents = Storage::disk($search->disk)->get($search->object_key);
        abort_unless($contents && hash_equals($search->sha256, hash('sha256', $contents)), 503);
        $photoId = Records::id();
        $key = 'product-requests/'.$photoId.'.webp';
        abort_unless(
            Storage::disk($search->disk)->put($key, $contents, [
                'visibility' => 'private',
                'ContentType' => 'image/webp',
            ]),
            503,
        );
        try {
            return DB::transaction(function () use ($http, $search, $data, $requests, $photoId, $key) {
                DB::table('photo_searches')->where('id', $search->id)->lockForUpdate()->first();
                $draft = $requests->create($http, $data, app(SessionIdentity::class)->hash($http));
                DB::table('product_request_photos')->insert([
                    'id' => $photoId,
                    'request_id' => $draft['id'],
                    'disk' => $search->disk,
                    'object_key' => $key,
                    'bytes' => $search->bytes,
                    'width' => $search->width,
                    'height' => $search->height,
                    'sha256' => $search->sha256,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $request = $requests->change(Records::row('product_requests', $draft['id']), []);
                $requests->event($request, 'photo_added', $http->user(), 'customer', [
                    'photo_id' => $photoId,
                    'from' => 'photo_search',
                ]);
                DB::table('photo_searches')
                    ->where('id', $search->id)
                    ->update(['product_request_id' => $draft['id'], 'updated_at' => now()]);

                return $requests->dto(Records::row('product_requests', $draft['id'], false));
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk($search->disk)->delete($key);

            throw $e;
        }
    }

    /** Short-lived: expired searches lose their photo and row. */
    public function purge(int $limit): int
    {
        $count = 0;
        foreach (
            DB::table('photo_searches')->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->get() as $search
        ) {
            try {
                Storage::disk($search->disk)->delete($search->object_key);
            } catch (\Throwable) {
                continue;
            }
            $count += DB::table('photo_searches')->where('id', $search->id)->delete();
        }

        return $count;
    }

    private function owned(Request $http, string $id): object
    {
        $user = $http->user();
        $hash = app(SessionIdentity::class)->hash($http, false);
        $search = DB::table('photo_searches')
            ->where('id', $id)
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($user, $hash) {
                $user ? $q->where('user_id', $user->id) : $q->whereRaw('1 = 0');
                if ($hash) {
                    $q->orWhere(fn ($g) => $g->whereNull('user_id')->where('guest_owner_hash', $hash));
                }
            })
            ->first();
        abort_unless($search, 404);

        return $search;
    }

    /** Similarity against public offers only, with the same filters as the catalogue. */
    public function rank(array $vector, string $model, array $filters): array
    {
        $query = app(PublicCatalogue::class)->query();
        if (! empty($filters['q'])) {
            app(TextSearch::class)->apply($query, $filters['q']);
        }
        if (! empty($filters['category'])) {
            $category = DB::table('categories')->where('slug', $filters['category'])->where('published', true)->first();
            $category
                ? $query->whereIn('products.category_id', app(CategoryAttributes::class)->descendantIds($category->id))
                : $query->whereRaw('1 = 0');
        }
        $scores = [];
        $offers = [];
        $query
            ->join('offer_embeddings AS e', 'e.offer_id', '=', 'offers.id')
            ->where('e.model', $model)
            ->addSelect(['e.vector'])
            ->orderBy('offers.id')
            ->cursor()
            ->each(function ($row) use ($vector, &$scores, &$offers) {
                $score = Vectors::dot($vector, Vectors::decode($row->vector));
                if ($score > ($scores[$row->id] ?? -2)) {
                    $scores[$row->id] = $score;
                    $offers[$row->id] = $row;
                }
            });
        $scores = array_filter($scores, fn ($s) => $s >= config('kora.ai.photo_similar'));
        arsort($scores);
        $top = array_slice($scores, 0, 12, true);
        $values = array_values($top);
        $level = ! $values
            ? 'none'
            : ($values[0] >= config('kora.ai.photo_match') &&
            (! isset($values[1]) || config('kora.ai.photo_margin') <= $values[0] - $values[1])
                ? 'match'
                : 'similar');
        $locale = app()->getLocale();
        $catalogue = app(PublicCatalogue::class);
        $results = [];
        foreach ($top as $offerId => $score) {
            $results[] = [
                'offer' => $catalogue->dto($offers[$offerId], $locale),
                'score' => round($score, 3),
                'label' => $level === 'match' && ! $results ? 'match' : 'similar',
            ];
        }

        return ['level' => $level, 'results' => $results];
    }

    private function dto(object $search, array $ranked, ?string $reason): array
    {
        return [
            'id' => $search->id,
            'status' => $search->status === 'completed' ? 'completed' : 'unavailable',
            'unavailable_reason' => $search->status === 'completed' ? null : $reason,
            'level' => $ranked['level'],
            'results' => $ranked['results'],
            'image' => ['width' => (int) $search->width, 'height' => (int) $search->height],
            'product_request_id' => $search->product_request_id,
            // Always offered: complete with words/filters, or ask the team for a product not found.
            'next' => ['refine' => (bool) $search->query_vector, 'request_product' => true],
            'expires_at' => CarbonImmutable::parse($search->expires_at, 'UTC')->toIso8601String(),
        ];
    }
}
