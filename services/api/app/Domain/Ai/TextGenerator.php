<?php

namespace App\Domain\Ai;

interface TextGenerator
{
    public function name(): string;

    public function configured(): bool;

    public function model(): string;

    /**
     * Structured answer forced through one tool whose input schema is $schema.
     *
     * @param  list<array{text?: string, image?: string}>  $content  image = WebP bytes
     * @return array{data: array, input_units: int, output_units: int}
     */
    public function structured(string $system, array $content, string $tool, array $schema, int $maxTokens): array;
}
