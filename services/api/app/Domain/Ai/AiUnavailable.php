<?php

namespace App\Domain\Ai;

/**
 * An AI feature could not answer: not configured, quota or budget reached, provider error or unusable output.
 * Callers fall back to the manual path; nothing else is blocked.
 */
final class AiUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
