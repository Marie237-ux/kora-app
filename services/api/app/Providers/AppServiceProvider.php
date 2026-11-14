<?php

namespace App\Providers;

use App\Domain\Ai\AnthropicGenerator;
use App\Domain\Ai\EmbeddingProvider;
use App\Domain\Ai\TextGenerator;
use App\Domain\Ai\VoyageEmbeddings;
use App\Domain\Documents\ClamAvScanner;
use App\Domain\Documents\DocumentScanner;
use App\Domain\Payments\MonetbilGateway;
use App\Domain\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentScanner::class, ClamAvScanner::class);
        $this->app->bind(PaymentGateway::class, MonetbilGateway::class);
        $this->app->bind(EmbeddingProvider::class, VoyageEmbeddings::class);
        $this->app->bind(TextGenerator::class, AnthropicGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
