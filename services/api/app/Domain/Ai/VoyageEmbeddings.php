<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Voyage AI multimodal embeddings (images and text in one space), written from the public API reference:
 * POST /v1/multimodalembeddings with base64 images. Disabled while VOYAGE_API_KEY is empty.
 */
final class VoyageEmbeddings implements EmbeddingProvider
{
    public function name(): string
    {
        return 'voyage';
    }

    public function configured(): bool
    {
        return filled(config('services.voyage.key'));
    }

    public function model(): string
    {
        return (string) config('services.voyage.model');
    }

    public function embed(array $inputs, string $type): array
    {
        $body = [
            'model' => $this->model(),
            'input_type' => $type,
            'inputs' => array_map(
                fn ($input) => [
                    'content' => isset($input['image'])
                        ? [
                            [
                                'type' => 'image_base64',
                                'image_base64' => 'data:image/webp;base64,'.base64_encode($input['image']),
                            ],
                        ]
                        : [['type' => 'text', 'text' => (string) $input['text']]],
                ],
                $inputs,
            ),
        ];
        try {
            $response = Http::withToken((string) config('services.voyage.key'))
                ->acceptJson()
                ->timeout(config('kora.ai.timeout'))
                ->post((string) config('services.voyage.url'), $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('provider_unreachable', $e);
        }
        if (! $response->successful()) {
            throw new AiUnavailable($response->status() === 429 ? 'provider_rate_limited' : 'provider_refused');
        }
        $vectors = collect($response->json('data') ?? [])
            ->sortBy('index')
            ->pluck('embedding')
            ->values()
            ->all();
        if (
            count($vectors) !== count($inputs) ||
            array_filter($vectors, fn ($v) => ! is_array($v) || ! $v || array_filter($v, fn ($x) => ! is_numeric($x)))
        ) {
            throw new AiUnavailable('invalid_output');
        }

        return ['vectors' => $vectors, 'units' => (int) $response->json('usage.total_tokens', 0)];
    }
}
