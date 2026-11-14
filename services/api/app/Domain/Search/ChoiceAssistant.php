<?php

namespace App\Domain\Search;

use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Choice assistant and comparator (§12). Questions come from the category's filterable characteristics, answers
 * are compared with published offers only, and every reason quotes a known value; a missing value is shown as
 * unknown, never guessed. Prices are the catalogue's current prices; nothing here creates an order or a price.
 */
final class ChoiceAssistant
{
    public function questions(string $slug): array
    {
        $category = $this->category($slug);
        $questions = [
            [
                'key' => 'budget',
                'type' => 'budget',
                'label' => ['fr' => 'Budget maximum', 'en' => 'Maximum budget'],
                'unit' => 'XAF',
            ],
        ];
        foreach ($this->definitions($category->id) as $d) {
            $questions[] = [
                'key' => $d['key'],
                'type' => $d['type'],
                'label' => $d['label'],
                'unit' => $d['unit'] ?? null,
                'options' => $d['type'] === 'choice' ? $d['options'] : null,
            ];
        }

        return [
            'category' => ['slug' => $category->slug, 'name' => json_decode($category->name, true)],
            'questions' => $questions,
            'offers' => $this->offers($category->id)->count(),
        ];
    }

    public function recommend(array $data): array
    {
        $category = $this->category($data['category']);
        $definitions = collect($this->definitions($category->id))->keyBy('key');
        $answers = [];
        foreach ($data['answers'] ?? [] as $key => $answer) {
            $d = $definitions->get($key);
            if (! $d) {
                throw ValidationException::withMessages(['answers.'.$key => __('catalogue.ATTRIBUTE_UNKNOWN')]);
            }
            $answers[$key] = $this->answer($d, $answer, 'answers.'.$key);
        }
        $budget = isset($data['budget']) ? (int) $data['budget'] : null;
        $locale = app()->getLocale();
        $catalogue = app(PublicCatalogue::class);
        $candidates = [];
        foreach ($this->offers($category->id)->orderBy('offers.id')->limit(500)->get() as $row) {
            // An unavailable offer or one above the budget is not recommended.
            if ($row->availability === 'unavailable' || ($budget !== null && (int) $row->selling_price > $budget)) {
                continue;
            }
            $values = json_decode($row->attributes, true);
            $reasons = [];
            $met = 0;
            $unknown = 0;
            foreach ($answers as $key => $wanted) {
                $d = $definitions[$key];
                $value = $values[$key] ?? null;
                $status = $value === null ? 'unknown' : ($this->satisfies($d, $value, $wanted) ? 'met' : 'not_met');
                $met += $status === 'met';
                $unknown += $status === 'unknown';
                $reasons[] = [
                    'key' => $key,
                    'label' => $d['label'],
                    'unit' => $d['unit'] ?? null,
                    'status' => $status,
                    'value' => $value,
                    'wanted' => $wanted,
                ];
            }
            $candidates[] = [
                'offer' => $catalogue->dto($row, $locale),
                'met' => $met,
                'unknown' => $unknown,
                'criteria' => count($answers),
                'reasons' => $reasons,
                'price' => (int) $row->selling_price,
            ];
        }
        usort(
            $candidates,
            fn ($a, $b) => [$b['met'], $a['unknown'], $a['price'], $a['offer']['id']] <=> [
                $a['met'],
                $b['unknown'],
                $b['price'],
                $b['offer']['id'],
            ],
        );
        $top = array_slice($candidates, 0, 5);

        return [
            'results' => array_map(
                fn ($c) => [
                    'offer' => $c['offer'],
                    'met' => $c['met'],
                    'unknown' => $c['unknown'],
                    'criteria' => $c['criteria'],
                    'reasons' => $c['reasons'],
                    'explanation' => $this->explain($c, $locale),
                ],
                $top,
            ),
            // No suitable offer: the site proposes the product request instead of pretending.
            'request_product' => ! $top || $top[0]['met'] < count($answers),
        ];
    }

    /** Side by side, by category characteristics; unknown values and incompatible units are marked as such. */
    public function compare(array $offerIds): array
    {
        $rows = app(PublicCatalogue::class)
            ->query()
            ->addSelect('products.category_id')
            ->whereIn('offers.id', $offerIds)
            ->get()
            ->keyBy('id');
        $locale = app()->getLocale();
        $found = array_values(array_filter($offerIds, fn ($id) => $rows->has($id)));
        $definitions = [];
        foreach ($found as $id) {
            foreach (app(CategoryAttributes::class)->definitions($rows[$id]->category_id) as $d) {
                $definitions[$d['key']][$id] = $d;
            }
        }
        $lines = [];
        foreach ($definitions as $key => $byOffer) {
            $first = reset($byOffer);
            $shapes = array_unique(array_map(fn ($d) => $d['type'].'|'.($d['unit'] ?? ''), $byOffer));
            $values = [];
            foreach ($found as $id) {
                $value = json_decode($rows[$id]->attributes, true)[$key] ?? null;
                $values[$id] = isset($byOffer[$id])
                    ? ['state' => $value === null ? 'unknown' : 'known', 'value' => $value]
                    : ['state' => 'not_applicable', 'value' => null];
            }
            $lines[] = [
                'key' => $key,
                'label' => $first['label'],
                'unit' => count($shapes) === 1 ? $first['unit'] ?? null : null,
                // Same characteristic defined with different types or units is shown, never compared.
                'comparable' => count($shapes) === 1 && count($byOffer) === count($found),
                'values' => $values,
            ];
        }
        $catalogue = app(PublicCatalogue::class);

        return [
            'offers' => array_map(fn ($id) => $catalogue->dto($rows[$id], $locale), $found),
            'unavailable' => array_values(array_diff($offerIds, $found)),
            'characteristics' => $lines,
            'share_path' => '/compare?offers='.implode(',', $found),
        ];
    }

    private function category(string $slug): object
    {
        $category = DB::table('categories')->where('slug', $slug)->where('published', true)->first();
        abort_unless($category, 404);

        return $category;
    }

    private function definitions(string $categoryId): array
    {
        return array_values(
            array_filter(
                app(CategoryAttributes::class)->definitions($categoryId),
                fn ($d) => ($d['filterable'] ?? false) && in_array($d['type'], ['choice', 'number', 'boolean'], true),
            ),
        );
    }

    private function offers(string $categoryId)
    {
        return app(PublicCatalogue::class)
            ->query()
            ->whereIn('products.category_id', app(CategoryAttributes::class)->descendantIds($categoryId));
    }

    private function answer(array $d, mixed $answer, string $field): mixed
    {
        $fail = fn () => throw ValidationException::withMessages([$field => __('catalogue.ATTRIBUTE_VALUE')]);
        if ($d['type'] === 'number') {
            if (
                ! is_array($answer) ||
                array_diff(array_keys($answer), ['min', 'max']) ||
                ! array_filter($answer, fn ($v) => $v !== null)
            ) {
                $fail();
            }
            $range = [];
            foreach (['min', 'max'] as $bound) {
                if (isset($answer[$bound])) {
                    $range[$bound] = app(CategoryAttributes::class)->value($d, $answer[$bound]);
                }
            }
            if (isset($range['min'], $range['max']) && (float) $range['min'] > (float) $range['max']) {
                $fail();
            }

            return $range;
        }
        if ($d['type'] === 'boolean') {
            is_bool($answer) || $fail();

            return $answer;
        }
        $values = (array) $answer;
        if (! $values || count($values) > 20) {
            $fail();
        }

        return array_values(array_map(fn ($v) => app(CategoryAttributes::class)->value($d, $v), $values));
    }

    private function satisfies(array $d, mixed $value, mixed $wanted): bool
    {
        return match ($d['type']) {
            'number' => (! isset($wanted['min']) || (float) $value >= (float) $wanted['min']) &&
                (! isset($wanted['max']) || (float) $value <= (float) $wanted['max']),
            'boolean' => $value === $wanted,
            default => in_array($value, $wanted, true),
        };
    }

    /** Short explanation from known data only. */
    private function explain(array $c, string $locale): string
    {
        $price = number_format($c['price'], 0, ',', ' ').' XAF';
        $unknown = array_map(
            fn ($r) => $r['label'][$locale] ?? $r['key'],
            array_filter($c['reasons'], fn ($r) => $r['status'] === 'unknown'),
        );
        $missing = array_map(
            fn ($r) => $r['label'][$locale] ?? $r['key'],
            array_filter($c['reasons'], fn ($r) => $r['status'] === 'not_met'),
        );
        if ($locale === 'en') {
            return trim(
                ($c['criteria'] ? "Meets {$c['met']} of {$c['criteria']} criteria. " : '').
                    ($missing ? 'Does not meet: '.implode(', ', $missing).'. ' : '').
                    ($unknown ? 'Unknown: '.implode(', ', $unknown).'. ' : '').
                    "Price today: {$price}.",
            );
        }

        return trim(
            ($c['criteria'] ? "Répond à {$c['met']} critère(s) sur {$c['criteria']}. " : '').
                ($missing ? 'Ne répond pas à : '.implode(', ', $missing).'. ' : '').
                ($unknown ? 'Inconnu : '.implode(', ', $unknown).'. ' : '').
                "Prix du jour : {$price}.",
        );
    }
}
