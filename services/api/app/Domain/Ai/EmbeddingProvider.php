<?php

namespace App\Domain\Ai;

interface EmbeddingProvider
{
    public function name(): string;

    public function configured(): bool;

    public function model(): string;

    /**
     * One vector per input, in the same shared space for images and text.
     *
     * @param  list<array{image?: string, text?: string}>  $inputs  image = WebP bytes
     * @return array{vectors: list<list<float>>, units: int}
     */
    public function embed(array $inputs, string $type): array;
}
