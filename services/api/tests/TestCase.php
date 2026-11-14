<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') === 'pgsql') {
            $connection = config('database.connections.pgsql');
            if (
                ! empty($connection['url']) ||
                $connection['database'] !== 'kora_test' ||
                $connection['host'] !== '127.0.0.1' ||
                (string) $connection['port'] !== '55432'
            ) {
                throw new \RuntimeException(
                    'Les tests PostgreSQL ne peuvent utiliser que la base isolée kora_test sur 127.0.0.1:55432.',
                );
            }
        }

        return $app;
    }
}
