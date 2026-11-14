<?php

namespace App\Domain\Shared;

use Illuminate\Validation\ValidationException;

final class Money
{
    public const MAX = 1_000_000_000_000_000_000;

    public static function parse(string $value): int
    {
        if (
            ! preg_match('/^(0|[1-9][0-9]{0,18})$/', $value) ||
            strlen($value) > strlen((string) self::MAX) ||
            (strlen($value) === strlen((string) self::MAX) && strcmp($value, (string) self::MAX) > 0)
        ) {
            self::fail('amount', 'AMOUNT_INVALID');
        }

        return (int) $value;
    }

    public static function sum(array $amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            if (! is_int($amount) || $amount < 0 || $amount > self::MAX - $sum) {
                self::fail('amount', 'AMOUNT_INVALID');
            }
            $sum += $amount;
        }

        return $sum;
    }

    public static function multiply(int $amount, int $quantity): int
    {
        if ($amount < 0 || $quantity < 0 || ($quantity > 0 && $amount > intdiv(self::MAX, $quantity))) {
            self::fail('amount', 'AMOUNT_INVALID');
        }

        return $amount * $quantity;
    }

    /** Largest remainder, stable ties; no multiplication of a large amount by 10 000. */
    public static function apportion(int $amount, array $basisPoints): array
    {
        if ($amount < 0 || $amount > self::MAX || array_sum($basisPoints) !== 10000) {
            self::fail('schedule', 'SCHEDULE_INVALID');
        }
        $parts = [];
        $remainders = [];
        foreach ($basisPoints as $index => $points) {
            if (! is_int($points) || $points < 1 || $points > 10000) {
                self::fail('schedule', 'SCHEDULE_INVALID');
            }
            $parts[$index] = intdiv($amount, 10000) * $points + intdiv(($amount % 10000) * $points, 10000);
            $remainders[$index] = (($amount % 10000) * $points) % 10000;
        }
        $remaining = $amount - array_sum($parts);
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        for ($i = 0; $i < $remaining; $i++) {
            $parts[$order[$i]]++;
        }

        return $parts;
    }

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('operations.'.$code)]);
    }
}
