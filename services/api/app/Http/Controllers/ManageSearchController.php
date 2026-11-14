<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use App\Domain\Search\SearchText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageSearchController extends OperationalController
{
    public function index(Request $http)
    {
        return $this->page(
            DB::table('search_synonyms')->orderBy('created_at')->orderBy('id'),
            $http,
            fn ($r) => $this->dto($r),
        );
    }

    public function save(Request $http, ?string $id = null)
    {
        $d = $http->validate([
            'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
            'locale' => ['required', 'in:fr,en'],
            'terms' => ['required', 'array', 'min:2', 'max:16'],
            'terms.*' => ['required', 'string', 'max:50'],
            'active' => ['required', 'boolean'],
        ]);
        $terms = [];
        foreach ($d['terms'] as $term) {
            $normalized = app(SearchText::class)->tokens($term);
            if (count($normalized) !== 1 || preg_match('/[\s\p{P}]/u', trim($term))) {
                throw ValidationException::withMessages(['terms' => __('search.SYNONYM_WORD')]);
            }
            $terms[] = $normalized[0];
        }
        $d['terms'] = array_values(array_unique($terms));
        if (count($d['terms']) < 2) {
            throw ValidationException::withMessages(['terms' => __('search.SYNONYM_WORD')]);
        }

        return $this->command(
            $http,
            'search.synonyms:'.($id ?? 'new'),
            $d,
            function ($data) use ($http, $id) {
                if ($id) {
                    $r = Records::row('search_synonyms', $id);
                    abort_unless((int) $r->version === $data['version'], 409);
                } else {
                    // Serialize the bounded configuration against other editors.
                    DB::table('business_settings')->insertOrIgnore([
                        'key' => 'search_synonyms_lock',
                        'value' => '{}',
                        'version' => 1,
                        'updated_by' => $http->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('business_settings')->where('key', 'search_synonyms_lock')->lockForUpdate()->first();
                    abort_if(DB::table('search_synonyms')->count() >= 200, 422);
                }
                $key = $id ?? Records::id();
                $values = [
                    'locale' => $data['locale'],
                    'terms' => Records::json($data['terms']),
                    'active' => $data['active'],
                    'version' => $id ? $r->version + 1 : 1,
                    'updated_at' => now(),
                ];
                if ($id) {
                    DB::table('search_synonyms')->where('id', $key)->update($values);
                } else {
                    DB::table('search_synonyms')->insert([...$values, 'id' => $key, 'created_at' => now()]);
                }
                Records::audit($http->user(), 'search.synonyms_saved', $key, ['version' => $values['version']]);

                return $this->dto(Records::row('search_synonyms', $key));
            },
            $id ? 200 : 201,
        );
    }

    public function misses(Request $http)
    {
        // No raw queries, IP, session, user ID or external URLs in the aggregate.
        return $this->page(
            DB::table('search_misses')->orderByDesc('day')->orderByDesc('occurrences')->orderBy('query_digest'),
            $http,
            fn ($r) => [
                'day' => $r->day,
                'locale' => $r->locale,
                'query_digest' => $r->query_digest,
                'token_count' => (int) $r->token_count,
                'occurrences' => (int) $r->occurrences,
            ],
        );
    }

    private function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'locale' => $r->locale,
            'terms' => json_decode($r->terms, true),
            'active' => (bool) $r->active,
            'version' => (int) $r->version,
        ];
    }
}
