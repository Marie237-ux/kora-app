<?php

namespace App\Domain\Search;

/** Unit-length float32 vectors stored as base64, so similarity is a plain dot product. */
final class Vectors
{
    public static function encode(array $vector): string
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector))) ?: 1.0;

        return base64_encode(pack('g*', ...array_map(fn ($x) => $x / $norm, $vector)));
    }

    public static function decode(string $stored): array
    {
        return array_values(unpack('g*', base64_decode($stored)));
    }

    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
