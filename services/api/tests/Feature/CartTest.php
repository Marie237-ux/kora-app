<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class CartTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    private function client(?User $user = null): User
    {
        $user ??= User::factory()->create(['roles' => ['client'], 'password' => 'TestOnly12345!']);
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeaders([
            'Origin' => 'http://localhost:4321',
            'Accept' => 'application/json',
        ]);

        return $user;
    }

    private function guest(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->flushSession()->withHeaders(['Origin' => 'http://localhost:4321', 'Accept' => 'application/json']);
        $this->app['session']->save();
    }

    private function fixture(): array
    {
        $this->operationUser();

        return $this->fixtureCatalogue();
    }

    private function cart(): array
    {
        return $this->getJson('/api/v1/cart')->assertOk()->json('data');
    }

    private function command(string $method, string $path, array $data, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? $this->fixtureId())->json(
            $method,
            '/api/v1/'.$path,
            $data,
        );
    }

    private function add(string $offerId, int $quantity = 1, bool $selected = true): array
    {
        return $this->command('POST', 'cart/lines', [
            'version' => $this->cart()['version'],
            'offer_id' => $offerId,
            'quantity' => $quantity,
            'selected' => $selected,
        ])
            ->assertCreated()
            ->json('data');
    }

    public function test_guest_cart_is_private_durable_in_session_and_mutations_are_versioned_and_idempotent(): void
    {
        $ctx = $this->fixture();
        $this->guest();
        $cart = $this->cart();
        $key = $this->fixtureId();
        $body = [
            'version' => $cart['version'],
            'offer_id' => $ctx['offer'],
            'quantity' => 2,
            'selected' => true,
            'unit_price' => 1,
            'user_id' => 1000,
        ];
        $r = $this->command('POST', 'cart/lines', $body, $key)
            ->assertCreated()
            ->assertJsonPath('data.selected_subtotal', '2000')
            ->json('data');
        $this->command('POST', 'cart/lines', $body, $key)->assertCreated()->assertJsonPath('data.lines.0.quantity', 2);
        $body['quantity'] = 3;
        $this->command('POST', 'cart/lines', $body, $key)->assertConflict();
        $this->command('PUT', 'cart/lines/'.$r['lines'][0]['id'], [
            'version' => 1,
            'quantity' => 3,
            'selected' => true,
        ])->assertConflict();
        $this->assertSame(2, $this->cart()['lines'][0]['quantity']);
        $this->withSession(['kora_anonymous_owner' => str_repeat('b', 64)]);
        $this->app['session']->save();
        $other = $this->cart();
        $this->assertCount(0, $other['lines']);
        $this->command('DELETE', 'cart/lines/'.$r['lines'][0]['id'], [
            'version' => $other['version'],
        ])->assertNotFound();
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_account_cart_is_found_from_another_session_and_cannot_be_edited_by_another_account(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $r = $this->add($ctx['offer'], 4);
        $this->guest();
        $this->client($owner);
        $this->assertSame($r['id'], $this->cart()['id']);
        $this->assertCount(1, $this->cart()['lines']);
        $this->client();
        $other = $this->cart();
        $this->assertCount(0, $other['lines']);
        $this->command('PUT', 'cart/lines/'.$r['lines'][0]['id'], [
            'version' => $other['version'],
            'quantity' => 1,
            'selected' => true,
        ])->assertNotFound();
    }

    public function test_real_login_merges_once_reports_combined_quantity_and_preserves_account_selection(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $account = $this->add($ctx['offer'], 3, false);
        $this->guest();
        $guest = $this->add($ctx['offer'], 2, true);
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'TestOnly12345!'])->assertOk();
        $key = $this->fixtureId();
        $body = ['version' => $account['version']];
        $merged = $this->command('POST', 'cart/merge', $body, $key)
            ->assertOk()
            ->assertJsonPath('data.merged', true)
            ->assertJsonPath('data.cart.lines.0.quantity', 5)
            ->assertJsonPath('data.cart.lines.0.selected', false)
            ->assertJsonPath('data.conflicts.0.kind', 'quantity_combined')
            ->json('data.cart');
        $this->command('POST', 'cart/merge', $body, $key)->assertOk()->assertJsonPath('data.cart.lines.0.quantity', 5);
        $this->command('POST', 'cart/merge', ['version' => $merged['version']])
            ->assertOk()
            ->assertJsonPath('data.merged', false);
        $this->assertDatabaseHas('carts', [
            'id' => $guest['id'],
            'merged_into' => $account['id'],
            'guest_owner_hash' => null,
        ]);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertDatabaseCount('supplier_payments', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_current_price_guarantee_and_availability_changes_require_explicit_current_hash_acceptance(): void
    {
        $ctx = $this->fixture();
        $this->client();
        $saved = $this->add($ctx['offer'], 2);
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update([
                'selling_price' => 1500,
                'warranty' => json_encode(['fr' => 'Nouvelle garantie de test', 'en' => 'New test warranty']),
            ]);
        $current = $this->cart();
        $this->assertTrue($current['needs_review']);
        $this->assertSame('3000', $current['selected_subtotal']);
        $this->assertSame(1000, $current['lines'][0]['saved_conditions']['unit_price']);
        $this->assertContains('warranty', $current['lines'][0]['changes']);
        $choice = ['id' => $current['lines'][0]['id'], 'conditions_hash' => $current['lines'][0]['conditions_hash']];
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['selling_price' => 1600]);
        $this->command('POST', 'cart/accept-changes', [
            'version' => $current['version'],
            'lines' => [$choice],
        ])->assertConflict();
        $current = $this->cart();
        $choice['conditions_hash'] = $current['lines'][0]['conditions_hash'];
        $accepted = $this->command('POST', 'cart/accept-changes', [
            'version' => $current['version'],
            'lines' => [$choice],
        ])
            ->assertOk()
            ->assertJsonPath('data.needs_review', false)
            ->assertJsonPath('data.checkout_ready', false)
            ->assertJsonPath('data.payable_total', null)
            ->json('data');
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        $unavailable = $this->cart();
        $this->assertNull($unavailable['selected_subtotal']);
        $this->assertNull($unavailable['lines'][0]['offer']);
        $this->command('PUT', 'cart/lines/'.$accepted['lines'][0]['id'], [
            'version' => $accepted['version'],
            'quantity' => 2,
            'selected' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.selected_subtotal', '0');
    }

    public function test_sharing_only_discloses_references_quantities_and_current_public_offers_and_is_revocable(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $cart = $this->add($ctx['offer'], 2);
        $share = $this->command('POST', 'cart/shares', [
            'version' => $cart['version'],
            'line_ids' => [$cart['lines'][0]['id']],
            'expires_in_days' => 1,
        ])
            ->assertCreated()
            ->json('data');
        $public = $this->getJson($share['url'])->assertOk()->assertJsonPath('data.lines.0.quantity', 2)->json('data');
        $this->assertArrayNotHasKey('user_id', $public);
        $this->assertArrayNotHasKey('cart_id', $public);
        $this->assertArrayNotHasKey('saved_conditions', $public['lines'][0]);
        $this->assertStringNotContainsString($owner->email, json_encode($public));
        $this->assertStringNotContainsString(
            substr($share['url'], -64),
            json_encode(DB::table('cart_shares')->first()),
        );
        $this->client();
        $recipient = $this->cart();
        $copied = $this->command('POST', substr($share['url'], 8).'/copy', ['version' => $recipient['version']])
            ->assertOk()
            ->assertJsonPath('data.copied', true)
            ->json('data.cart');
        $this->command('POST', substr($share['url'], 8).'/copy', ['version' => $copied['version']])
            ->assertOk()
            ->assertJsonPath('data.copied', false);
        $this->command('POST', 'cart/shares/'.$share['id'].'/revoke', [
            'version' => $copied['version'],
        ])->assertNotFound();
        $this->client($owner);
        $this->command('POST', 'cart/shares/'.$share['id'].'/revoke', ['version' => $cart['version']])->assertOk();
        $this->getJson($share['url'])->assertNotFound();
        $this->assertSame(2, $this->cart()['lines'][0]['quantity']);
    }

    public function test_copy_reprices_and_skips_archived_items_and_an_expired_share_cannot_be_used(): void
    {
        $ctx = $this->fixture();
        $this->client();
        $cart = $this->add($ctx['offer']);
        $share = $this->command('POST', 'cart/shares', [
            'version' => $cart['version'],
            'line_ids' => [$cart['lines'][0]['id']],
            'expires_in_days' => 1,
        ])
            ->assertCreated()
            ->json('data');
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['selling_price' => 2200]);
        $this->client();
        $copied = $this->command('POST', substr($share['url'], 8).'/copy', ['version' => $this->cart()['version']])
            ->assertOk()
            ->assertJsonPath('data.cart.lines.0.saved_conditions.unit_price', 2200)
            ->json('data.cart');
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        $this->client();
        $this->command('POST', substr($share['url'], 8).'/copy', ['version' => $this->cart()['version']])
            ->assertOk()
            ->assertJsonPath('data.skipped.0.reason', 'unavailable')
            ->assertJsonCount(0, 'data.cart.lines');
        $this->travel(2)->days();
        $this->getJson($share['url'])->assertNotFound();
    }

    public function test_large_totals_are_exact_strings_and_unselected_items_are_retained(): void
    {
        $ctx = $this->fixture();
        $this->client();
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['selling_price' => 1000000000000]);
        $cart = $this->add($ctx['offer'], 1000000);
        $this->assertSame('1000000000000000000', $cart['selected_subtotal']);
        $this->command('PUT', 'cart/lines/'.$cart['lines'][0]['id'], [
            'version' => $cart['version'],
            'quantity' => 1000000,
            'selected' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.selected_subtotal', '0')
            ->assertJsonCount(1, 'data.lines');
    }

    public function test_merge_preserves_distinct_variants_and_transfers_guest_share_control(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $account = $this->add($ctx['offer'], 1);
        $variant = $this->fixtureId();
        $offer = $this->fixtureId();
        DB::table('variants')->insert([
            'id' => $variant,
            'product_id' => $ctx['product'],
            'sku' => 'OTHER-'.$variant,
            'attributes' => '{}',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $copy = (array) DB::table('offers')->find($ctx['offer']);
        $copy['id'] = $offer;
        $copy['variant_id'] = $variant;
        DB::table('offers')->insert($copy);
        $this->guest();
        $guest = $this->add($offer, 2);
        $share = $this->command('POST', 'cart/shares', [
            'version' => $guest['version'],
            'line_ids' => [$guest['lines'][0]['id']],
            'expires_in_days' => 2,
        ])
            ->assertCreated()
            ->json('data');
        $this->client($owner);
        $this->assertTrue($this->cart()['guest_merge_available']);
        $merged = $this->command('POST', 'cart/merge', ['version' => $account['version']])
            ->assertOk()
            ->assertJsonCount(2, 'data.cart.lines')
            ->assertJsonPath('data.conflicts.0.kind', 'distinct_variant_preserved')
            ->json('data.cart');
        $this->assertFalse($this->cart()['guest_merge_available']);
        $this->command('POST', 'cart/shares/'.$share['id'].'/revoke', [
            'version' => $merged['version'],
        ])->assertOk();
        $this->getJson($share['url'])->assertNotFound();
    }

    public function test_merge_quantity_overflow_rolls_back_and_keeps_both_carts_intact(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $account = $this->add($ctx['offer'], 1000000);
        $this->guest();
        $guest = $this->add($ctx['offer'], 1);
        $this->client($owner);
        $this->command('POST', 'cart/merge', ['version' => $account['version']])->assertUnprocessable();
        $this->assertDatabaseHas('cart_lines', ['cart_id' => $account['id'], 'quantity' => 1000000]);
        $this->assertDatabaseHas('cart_lines', ['cart_id' => $guest['id'], 'quantity' => 1]);
        $this->assertDatabaseHas('carts', ['id' => $guest['id'], 'merged_into' => null]);
    }
}
