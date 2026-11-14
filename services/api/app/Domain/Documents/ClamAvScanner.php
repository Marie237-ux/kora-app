<?php

namespace App\Domain\Documents;

use Symfony\Component\Process\Process;

final class ClamAvScanner implements DocumentScanner
{
    public function scan(string $localPath): string
    {
        $binary = config('kora.clamscan_binary');
        if (! is_string($binary) || $binary === '') {
            return 'pending';
        }
        try {
            $process = new Process([$binary, '--no-summary', '--', $localPath]);
            $process->setTimeout(30);
            $process->run();

            return match ($process->getExitCode()) {
                0 => 'clean',
                1 => 'infected',
                default => 'pending',
            };
        } catch (\Throwable) {
            return 'pending';
        }
    }
}
