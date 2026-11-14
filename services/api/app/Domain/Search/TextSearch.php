<?php

namespace App\Domain\Search;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TextSearch
{
    public function apply(Builder $query, string $text): array
    {
        $tokens = app(SearchText::class)->tokens($text);
        if (count($tokens) > 8) {
            throw ValidationException::withMessages(['q' => __('search.TOO_MANY_WORDS')]);
        }
        if (! $tokens) {
            $query->whereRaw('1 = 0');

            return [];
        }
        $groups = DB::table('search_synonyms')
            ->where('active', true)
            ->get(['terms'])
            ->map(fn ($r) => json_decode($r->terms, true));
        foreach ($tokens as $word) {
            $alternatives = [$word];
            foreach ($groups as $group) {
                if (in_array($word, $group, true)) {
                    $alternatives = [...$alternatives, ...$group];
                }
            }
            $prefixes =
                strlen($word) >= 3
                    ? DB::table('catalogue_search_tokens')
                        ->where('token', 'like', $word.'%')
                        ->distinct()
                        ->orderBy('token')
                        ->limit(16)
                        ->pluck('token')
                        ->all()
                    : [];
            $alternatives = array_values(array_unique([...$alternatives, ...$prefixes]));
            $exactExists = DB::table('catalogue_search_tokens')->whereIn('token', $alternatives)->exists();
            if (! $exactExists && strlen($word) >= 4) {
                // Bounded fallback, never a full catalogue scan or a claim of certain identification.
                $distance = strlen($word) >= 8 ? 2 : 1;
                $vocabulary = DB::table('catalogue_search_tokens')
                    ->whereRaw('LENGTH(token) BETWEEN ? AND ?', [strlen($word) - $distance, strlen($word) + $distance])
                    ->distinct()
                    ->orderBy('token')
                    ->limit(2000)
                    ->pluck('token');
                $near = $vocabulary
                    ->map(fn ($term) => ['term' => $term, 'distance' => levenshtein($word, $term)])
                    ->filter(fn ($v) => $v['distance'] <= $distance)
                    ->sortBy('distance')
                    ->take(12)
                    ->pluck('term')
                    ->all();
                $alternatives = array_values(array_unique([...$alternatives, ...$near]));
            }
            $alternatives = array_slice($alternatives, 0, 64);
            $query->whereExists(
                fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('catalogue_search_tokens as st')
                    ->whereColumn('st.offer_id', 'offers.id')
                    ->whereIn('st.token', $alternatives),
            );
        }

        return $tokens;
    }

    public function miss(array $tokens, string $locale): void
    {
        if (! $tokens) {
            return;
        }
        sort($tokens);
        $day = now('Africa/Douala')->toDateString();
        $digest = hash_hmac('sha256', $day.':'.implode(' ', $tokens), config('app.key'));
        $key = ['day' => $day, 'locale' => $locale, 'query_digest' => $digest];
        DB::table('search_misses')->insertOrIgnore([...$key, 'token_count' => count($tokens), 'occurrences' => 0]);
        DB::table('search_misses')->where($key)->increment('occurrences');
    }
}
