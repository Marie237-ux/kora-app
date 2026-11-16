<?php

namespace Tests\Feature;

use App\Domain\Notifications\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class EngagementTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, PreparedOrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function account(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->json($method, '/api/v1/'.$path, $data);
    }

    public function test_reviews_of_delivered_purchases_are_moderated_but_never_refused_for_being_negative(): void
    {
        Storage::fake(config('kora.media_disk'));
        $o = $this->warehouseOrder(1);
        $this->readyOrder($o['order'], 1);
        $line = $o['order']['lines'][0]['id'];
        $this->client($o['client']);
        $review = ['order_line_id' => $line, 'rating' => 1, 'body' => 'Arrived late, box crushed (test).'];
        $this->account('POST', 'account/reviews', $review)->assertUnprocessable();
        // Fixture shortcut: the courier handover writes this date; reviews only read it.
        DB::table('orders')->update(['delivered_at' => now()]);
        $mine = $this->account('POST', 'account/reviews', $review)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');
        $this->account('POST', 'account/reviews', [...$review, 'rating' => 2])
            ->assertCreated()
            ->assertJsonPath('data.rating', 2);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/account/reviews/'.$mine['id'].'/photos', [
                'file' => UploadedFile::fake()->image('box.png', 120, 90),
            ])
            ->assertCreated()
            ->assertJsonPath('data.photos', 1);
        // 1 to 3 photos per review: a fourth one is refused.
        foreach ([2, 3] as $n) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->post('/api/v1/account/reviews/'.$mine['id'].'/photos', ['file' => UploadedFile::fake()->image('box'.$n.'.png', 120, 90)])
                ->assertCreated()
                ->assertJsonPath('data.photos', $n);
        }
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/account/reviews/'.$mine['id'].'/photos', ['file' => UploadedFile::fake()->image('box4.png', 120, 90)])
            ->assertUnprocessable();
        $product = DB::table('product_reviews')->value('product_id');
        $this->getJson('/api/v1/products/'.$product.'/reviews')->assertJsonPath('data.summary.count', 0);

        $this->operationUser('support');
        $staff = $this->op('GET', 'reviews?status=pending')->json('data.0');
        $this->op('POST', 'reviews/'.$staff['id'].'/moderation', [
            'version' => $staff['version'],
            'decision' => 'reject',
            'reason' => 'negative',
        ])->assertUnprocessable();
        // No approved photo: the review cannot be published.
        $this->op('POST', 'reviews/'.$staff['id'].'/moderation', [
            'version' => $staff['version'],
            'decision' => 'publish',
            'approved_photos' => [],
        ])->assertUnprocessable();
        $staff = $this->op('POST', 'reviews/'.$staff['id'].'/moderation', [
            'version' => $staff['version'],
            'decision' => 'publish',
            'approved_photos' => [$staff['photo_ids'][0]['id']],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->json('data');
        $this->assertTrue((bool) DB::table('media_assets')->value('is_public'));

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/products/'.$product.'/reviews')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.average', 2)
            ->assertJsonPath('data.reviews.0.verified_purchase', true)
            ->json('data.reviews.0');
        $this->assertCount(1, $public['photos']);
        $this->assertStringNotContainsString($o['client']->email, json_encode($public));

        $this->client($o['client']);
        $this->account('POST', 'account/reviews', $review)->assertUnprocessable();
        $this->account('POST', 'reviews/'.$staff['id'].'/report', ['reason' => 'spam'])->assertUnprocessable();
        $this->client();
        $this->account('POST', 'reviews/'.$staff['id'].'/report', [
            'reason' => 'spam',
            'note' => 'Looks fine actually',
        ])->assertCreated();

        $this->operationUser('support');
        $report = DB::table('review_reports')->value('id');
        $this->op('POST', 'review-reports/'.$report.'/handle', ['action' => 'dismiss'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->op('POST', 'reviews/'.$staff['id'].'/reply', [
            'version' => (int) DB::table('product_reviews')->value('version'),
            'body' => 'Sorry for the delay; we changed carrier packaging (test).',
        ])->assertOk();
        $this->getJson('/api/v1/products/'.$product.'/reviews')->assertJsonPath(
            'data.reviews.0.reply',
            'Sorry for the delay; we changed carrier packaging (test).',
        );
        $this->operationUser('stock');
        $this->op('GET', 'reviews')->assertForbidden();
    }

    public function test_alerts_notify_once_in_the_user_language_and_preferences_and_history_are_respected(): void
    {
        $o = $this->warehouseOrder(1);
        $user = $o['client'];
        DB::table('users')
            ->where('id', $user->id)
            ->update(['locale' => 'en']);
        $this->client($user);
        $offer = $o['ctx']['offer'];
        $this->account('POST', 'account/alerts', [
            'offer_id' => $offer,
            'kind' => 'price_drop',
            'target_price' => 1000,
        ])->assertUnprocessable();
        $this->account('POST', 'account/alerts', [
            'offer_id' => $offer,
            'kind' => 'back_in_stock',
        ])->assertUnprocessable();
        $this->account('POST', 'account/alerts', ['offer_id' => $offer, 'kind' => 'price_drop'])
            ->assertCreated()
            ->assertJsonPath('data.baseline_price', '1000');
        $this->account('POST', 'account/alerts', ['offer_id' => $offer, 'kind' => 'price_drop'])->assertUnprocessable();
        $this->artisan('alerts:check')->expectsOutput('Alerts triggered: 0')->assertSuccessful();

        // Fixture shortcut: a lower published price, as the catalogue would set it.
        DB::table('offers')
            ->where('id', $offer)
            ->update(['selling_price' => 900]);
        $this->artisan('alerts:check')->expectsOutput('Alerts triggered: 1')->assertSuccessful();
        $this->artisan('alerts:check')->expectsOutput('Alerts triggered: 0')->assertSuccessful();
        $notice = $this->account('GET', 'account/inbox')->assertOk()->json('data.0');
        $this->assertSame('Price drop', $notice['title']);
        $this->assertSame(
            [
                'email' => 'skipped_not_configured',
                'web_push' => 'skipped_not_configured',
                'whatsapp' => 'skipped_preference',
            ],
            collect($notice['deliveries'])->pluck('status', 'channel')->all(),
        );
        $this->account('POST', 'account/inbox/'.$notice['id'].'/read')->assertOk();

        // Reminders switched off in the centre: the next alert fires but writes no notice.
        $this->account('PUT', 'account/notification-preferences', ['reminder' => ['in_app' => false]])
            ->assertOk()
            ->assertJsonPath('data.reminder.in_app', false)
            ->assertJsonPath('data.marketing.email', false);
        $this->account('POST', 'account/alerts', ['offer_id' => $offer, 'kind' => 'price_drop'])->assertCreated();
        DB::table('offers')
            ->where('id', $offer)
            ->update(['selling_price' => 800]);
        $this->artisan('alerts:check')->expectsOutput('Alerts triggered: 1')->assertSuccessful();
        $this->assertSame(1, DB::table('user_notifications')->count());

        // One-click unsubscribe works without a session; a forged token or a transactional category does not.
        $notifications = app(Notifications::class);
        $this->app['auth']->forgetGuards();
        $link = fn (string $token, string $category = 'reminder') => '/api/v1/notification-preferences/unsubscribe?'.
            http_build_query([
                'user' => $user->id,
                'channel' => 'email',
                'category' => $category,
                'token' => $token,
            ]);
        $this->getJson($link(str_repeat('0', 40)))->assertNotFound();
        $this->getJson(
            $link($notifications->unsubscribeToken($user->id, 'email', 'transactional'), 'transactional'),
        )->assertUnprocessable();
        $this->getJson($link($notifications->unsubscribeToken($user->id, 'email', 'reminder')))->assertOk();
        $this->assertFalse($notifications->enabled($user->id, 'email', 'reminder'));

        $this->client($user);
        $product = DB::table('products')->value('id');
        $this->account('POST', 'account/viewed', ['product_id' => $product])->assertJsonPath('data.recorded', true);
        $this->account('GET', 'account/viewed')->assertJsonCount(1, 'data')->assertJsonPath('meta.enabled', true);
        $this->account('PUT', 'account/viewed/settings', ['enabled' => false])->assertOk();
        $this->account('POST', 'account/viewed', ['product_id' => $product])->assertJsonPath('data.recorded', false);
        $this->account('GET', 'account/viewed')->assertJsonCount(0, 'data');

        $this->account('POST', 'account/buy-again', ['order_line_id' => $o['order']['lines'][0]['id']])
            ->assertOk()
            ->assertJsonPath('data.added', true);
        $this->assertSame(
            1,
            DB::table('cart_lines')
                ->join('carts', 'carts.id', '=', 'cart_lines.cart_id')
                ->where('carts.user_id', $user->id)
                ->count(),
        );
        $this->account('POST', 'account/push-subscriptions', [
            'endpoint' => 'https://push.example.test/sub/1',
            'keys' => ['p256dh' => 'test-key', 'auth' => 'test-auth'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.sending_configured', false);
        $this->client();
        $this->account('POST', 'account/buy-again', [
            'order_line_id' => $o['order']['lines'][0]['id'],
        ])->assertNotFound();
    }
}
