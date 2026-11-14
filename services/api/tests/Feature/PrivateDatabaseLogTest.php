<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class PrivateDatabaseLogTest extends TestCase
{
    public function test_query_exception_logging_excludes_sql_bindings_and_private_documents(): void
    {
        $pdo = new \PDOException('Sensitive DB detail');
        $pdo->errorInfo = ['23514', null, 'Private detail'];
        $error = new QueryException(
            'pgsql',
            'insert into private_documents values (?)',
            ['PRIVATE-CONTRACT-TEXT'],
            $pdo,
        );
        Log::shouldReceive('error')
            ->once()
            ->with(
                'Database operation failed',
                \Mockery::on(
                    fn ($context) => array_keys($context) === ['request_id', 'sql_state'] &&
                        $context['sql_state'] === '23514',
                ),
            );
        $this->app->make(ExceptionHandler::class)->report($error);
        $this->addToAssertionCount(1);
    }
}
