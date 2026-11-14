<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Claude through the Messages API; the answer is forced into one tool call so it arrives as JSON matching a
 * schema, which the server validates again. Disabled while ANTHROPIC_API_KEY is empty.
 */
final class AnthropicGenerator implements TextGenerator
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    public function model(): string
    {
        return (string) config('services.anthropic.model');
    }

    public function structured(string $system, array $content, string $tool, array $schema, int $maxTokens): array
    {
        $blocks = array_map(
            fn ($part) => isset($part['image'])
                ? [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/webp',
                        'data' => base64_encode($part['image']),
                    ],
                ]
                : ['type' => 'text', 'text' => (string) $part['text']],
            $content,
        );
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->acceptJson()
                ->timeout(config('kora.ai.timeout'))
                ->post((string) config('services.anthropic.url'), [
                    'model' => $this->model(),
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $blocks]],
                    'tools' => [['name' => $tool, 'description' => 'Return the proposal.', 'input_schema' => $schema]],
                    'tool_choice' => ['type' => 'tool', 'name' => $tool],
                ]);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('provider_unreachable', $e);
        }
        if (! $response->successful()) {
            throw new AiUnavailable($response->status() === 429 ? 'provider_rate_limited' : 'provider_refused');
        }
        $call = collect($response->json('content') ?? [])->first(
            fn ($b) => ($b['type'] ?? null) === 'tool_use' && ($b['name'] ?? null) === $tool,
        );
        if (! $call || ! is_array($call['input'] ?? null)) {
            throw new AiUnavailable('invalid_output');
        }

        return [
            'data' => $call['input'],
            'input_units' => (int) $response->json('usage.input_tokens', 0),
            'output_units' => (int) $response->json('usage.output_tokens', 0),
        ];
    }
}
