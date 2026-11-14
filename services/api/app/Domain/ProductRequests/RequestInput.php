<?php

namespace App\Domain\ProductRequests;

use App\Domain\Shared\Money;
use Illuminate\Support\Facades\Validator;

final class RequestInput
{
    public function details(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'brand' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:150'],
            'variant' => ['nullable', 'string', 'max:500'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'budget' => ['nullable', 'string'],
            'reference_url' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $data = array_replace(
            array_fill_keys(
                ['name', 'description', 'brand', 'reference', 'variant', 'quantity', 'budget', 'reference_url'],
                null,
            ),
            $data,
        );
        foreach (['name', 'description', 'brand', 'reference', 'variant'] as $key) {
            $data[$key] = $data[$key] === null ? null : trim($data[$key]);
        }
        if ($data['quantity'] !== null) {
            $data['quantity'] = (int) $data['quantity'];
        }
        if ($data['budget'] !== null) {
            $data['budget'] = (string) Money::parse($data['budget']);
        }
        if ($data['reference_url'] !== null) {
            $url = $data['reference_url'];
            $parts = parse_url($url);
            if (
                ! filter_var($url, FILTER_VALIDATE_URL) ||
                ! $parts ||
                ($parts['scheme'] ?? '') !== 'https' ||
                isset($parts['user']) ||
                isset($parts['pass']) ||
                preg_match('/[\x00-\x20\\\\]/', $url)
            ) {
                Money::fail('reference_url', 'REQUEST_REFERENCE_INVALID');
            }
        }

        // A reference is stored only. Never fetch a URL provided by a customer.
        return $data;
    }
}
