<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'throttle:5,1'])->get('/api/test-only/busy', fn () => ['ok' => true]);
        Route::middleware(['api', 'throttle:2,1'])->get('/api/test-only/rare', fn () => ['ok' => true]);
        Route::middleware(['api', 'throttle:2,1'])->get('/api/test-only/rare/{id}', fn () => ['ok' => true]);
    }

    public function test_each_route_has_its_own_counter_per_user(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['client']]));
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/test-only/busy')->assertOk();
        }
        $this->getJson('/api/test-only/busy')->assertTooManyRequests();
        // Activity on another route no longer consumes this route's small allowance.
        $this->getJson('/api/test-only/rare')->assertOk();
        $this->getJson('/api/test-only/rare')->assertOk();
        $this->getJson('/api/test-only/rare')->assertTooManyRequests();
        // One counter per route pattern, not per resource: changing the id does not reset the limit.
        $this->getJson('/api/test-only/rare/1')->assertOk();
        $this->getJson('/api/test-only/rare/2')->assertOk();
        $this->getJson('/api/test-only/rare/3')->assertTooManyRequests();

        // Another user keeps a separate allowance.
        $this->actingAs(User::factory()->create(['roles' => ['client']]));
        $this->getJson('/api/test-only/rare')->assertOk();
    }
}
