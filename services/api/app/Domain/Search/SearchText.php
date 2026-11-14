<?php

namespace App\Domain\Search;

use Illuminate\Support\Str;

final class SearchText
{
    public function tokens(string $text): array
    {
        $normalized = strtolower(Str::ascii(mb_strtolower($text, 'UTF-8')));
        preg_match_all('/[a-z0-9]{1,50}/', $normalized, $matches);

        return array_values(array_unique($matches[0]));
    }
}
