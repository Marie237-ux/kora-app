<?php

namespace App\Domain\Catalogue;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CategoryAttributes
{
    public function ancestry(string $id, ?Collection $all = null): array
    {
        $all ??= DB::table('categories')->get()->keyBy('id');
        $chain = [];
        while ($id) {
            if (isset($chain[$id]) || count($chain) >= 8 || ! $all->has($id)) {
                $this->fail('category_id', 'CATEGORY_TREE');
            }
            $row = $all->get($id);
            $chain[$id] = $row;
            $id = $row->parent_id ?? '';
        }

        return array_values($chain);
    }

    public function definitions(string $id, ?Collection $all = null): array
    {
        $definitions = [];
        foreach (array_reverse($this->ancestry($id, $all)) as $row) {
            foreach (json_decode($row->attribute_definitions ?? '[]', true) as $definition) {
                if (isset($definitions[$definition['key']])) {
                    $this->fail('attributes', 'ATTRIBUTE_DUPLICATE');
                }
                $definitions[$definition['key']] = $definition;
            }
        }

        return array_values($definitions);
    }

    public function descendantIds(string $id): array
    {
        $all = DB::table('categories')->get()->keyBy('id');

        return $all
            ->filter(
                fn ($row) => $row->published && in_array($id, array_column($this->ancestry($row->id, $all), 'id'), true),
            )
            ->keys()
            ->all();
    }

    public function validateDefinitions(array $definitions): void
    {
        $keys = [];
        foreach ($definitions as $definition) {
            $key = $definition['key'];
            if (isset($keys[$key])) {
                $this->fail('attributes', 'ATTRIBUTE_DUPLICATE');
            }
            $keys[$key] = true;
            if ($definition['type'] === 'choice' && empty($definition['options'])) {
                $this->fail('attributes.'.$key, 'ATTRIBUTE_OPTIONS');
            }
            $options = [];
            foreach ($definition['options'] ?? [] as $option) {
                if (isset($options[$option['value']])) {
                    $this->fail('attributes.'.$key, 'ATTRIBUTE_OPTIONS');
                }
                $options[$option['value']] = true;
            }
        }
    }

    public function values(string $categoryId, array $values, bool $required = true): array
    {
        $definitions = collect($this->definitions($categoryId))->keyBy('key');
        foreach (array_keys($values) as $key) {
            if (! $definitions->has($key)) {
                $this->fail('attributes.'.$key, 'ATTRIBUTE_UNKNOWN');
            }
        }
        $result = [];
        foreach ($definitions as $key => $definition) {
            $value = $values[$key] ?? null;
            if ($value === '' || $value === null) {
                if ($required && $definition['required']) {
                    $this->fail('attributes.'.$key, 'ATTRIBUTE_REQUIRED');
                }

                continue;
            }
            $result[$key] = $this->value($definition, $value);
        }

        return $result;
    }

    public function value(array $definition, mixed $value): string|bool
    {
        $field = 'attributes.'.$definition['key'];
        if ($definition['type'] === 'boolean') {
            if (! is_bool($value)) {
                $this->fail($field, 'ATTRIBUTE_VALUE');
            }

            return $value;
        }
        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, 'ATTRIBUTE_VALUE');
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > 500) {
            $this->fail($field, 'ATTRIBUTE_VALUE');
        }
        if ($definition['type'] === 'number') {
            if (! preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $value)) {
                $this->fail($field, 'ATTRIBUTE_VALUE');
            }
            $negative = str_starts_with($value, '-');
            $value = ltrim($value, '-');
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $whole = ltrim($whole, '0') ?: '0';
            $fraction = rtrim($fraction, '0');

            return ($negative && ($whole !== '0' || $fraction !== '') ? '-' : '').
                $whole.
                ($fraction !== '' ? '.'.$fraction : '');
        }
        if (
            $definition['type'] === 'choice' &&
            ! in_array($value, array_column($definition['options'], 'value'), true)
        ) {
            $this->fail($field, 'ATTRIBUTE_VALUE');
        }

        return $value;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('catalogue.'.$message)]);
    }
}
