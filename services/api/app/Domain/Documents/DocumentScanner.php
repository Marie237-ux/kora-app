<?php

namespace App\Domain\Documents;

interface DocumentScanner
{
    /** clean, infected or pending; pending is never treated as clean. */
    public function scan(string $localPath): string;
}
