<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class ReferralsTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, PreparedOrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function account(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->json(
            $method,
            '/api/v1/account/'.$path,
            $data,
        );
    }

    private function program(int $budget): array
    {
        $this->operationUser();
        $program = $this->op('POST', 'referral/programs', [
            'rate_bp' => 200,
            'purchase_cap' => 1000,
            'purchases' => 2,
            'payout_threshold' => 30,
            'validation_days' => 7,
            'budget' => $budget,
            'terms' => ['fr' => 'Conditions de test.', 'en' => 'Test terms.'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        return $this->op('POST', 'referral/programs/'.$program['id'].'/activate')
            ->assertOk()
            ->json('data');
    }

    /** A paid order of one 1 000 XAF unit for the signed-in customer. */
    private function buy(array $o, User $customer): array
    {
        // The payment notification runs without the customer's session: sign them in again for each order.
        $this->client($customer);
        $quote = $this->quote($this->fill([[$o['ctx']['offer'], 1]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => DB::table('delivery_slot_rules')->value('id'),
            'date' => now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $order = $this->order($quote, '2000')->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $tx = 'TEST-'.$this->fixtureId();
        $this->gateway->paid($tx, $attempt['id'], 2000);
        $this->notify([
            'transaction_id' => $tx,
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();

        return $order;
    }

    /** Fixture shortcut: the real handover writes this date; referral logic only reads it. */
    private function delivered(array $order): void
    {
        DB::table('orders')
            ->where('id', $order['id'])
            ->update(['delivered_at' => now()]);
    }

    public function test_the_sponsor_earns_on_two_qualifying_purchases_net_of_returns_and_within_budget(): void
    {
        $o = $this->warehouseOrder(1);
        $sponsor = $o['client'];
        // Room for the sponsor's order and the referee's three on the same test slot.
        DB::table('delivery_slot_rules')->update(['capacity' => 10]);
        $this->program(30);

        $this->client($sponsor);
        $code = $this->account('GET', 'referral')->assertOk()->json('data.code');
        $this->assertMatchesRegularExpression('/^K[A-Z0-9]{7}$/', $code);
        $this->account('POST', 'referral/join', ['code' => $code])->assertUnprocessable();

        $referee = $this->client();
        $this->account('POST', 'referral/join', ['code' => 'KNOTREAL'])->assertUnprocessable();
        $this->account('POST', 'referral/join', ['code' => strtolower($code)])->assertCreated();
        $this->account('POST', 'referral/join', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code', 'error.fields');
        // One minute apart: purchases are rewarded in payment order, and ties would fall back to random ids.
        $orders = [];
        foreach (range(1, 3) as $i) {
            $orders[] = $this->buy($o, $referee);
            $this->travel(1)->minutes();
        }
        foreach ($orders as $order) {
            $this->delivered($order);
        }

        $this->artisan('referrals:reconcile')->assertSuccessful();
        $this->assertSame(2, DB::table('referral_rewards')->count());
        $this->assertSame(
            ['pending', 'pending'],
            DB::table('referral_rewards')->orderBy('sequence')->pluck('status')->all(),
        );

        // The second purchase is fully refunded before the delay ends: no reward for it.
        $this->operationUser();
        $refund = $this->refund($orders[1], '1000')->assertCreated()->json('data');
        $this->transfer($refund, '1000', 'TEST-REFUND-REF')->assertOk();
        $this->travel(8)->days();
        $this->artisan('referrals:reconcile')->assertSuccessful();
        $this->assertSame(
            ['validated', 'cancelled'],
            DB::table('referral_rewards')->orderBy('sequence')->pluck('status')->all(),
        );
        $this->assertSame(20, (int) DB::table('referral_rewards')->where('sequence', 1)->value('amount'));
        // The next purchase takes its place; only 10 XAF of budget is left for it.
        $this->artisan('referrals:reconcile')->assertSuccessful();
        $third = DB::table('referral_rewards')->where('order_id', $orders[2]['id'])->first();
        $this->assertSame('validated', $third->status);
        $this->assertSame(10, (int) $third->amount);

        $this->client($sponsor);
        $mine = $this->account('GET', 'referral')
            ->assertJsonPath('data.balance', '30')
            ->assertJsonPath('data.referees.0.label', '#1')
            ->json('data');
        $this->assertStringNotContainsString($referee->email, json_encode($mine));
        $this->assertStringNotContainsString($orders[0]['number'], json_encode($mine));
        $this->account('POST', 'referral/payouts', [
            'amount' => 31,
            'channel' => 'mobile_money',
            'destination' => '600000000',
        ])->assertUnprocessable();
        $payout = $this->account('POST', 'referral/payouts', [
            'amount' => 30,
            'channel' => 'mobile_money',
            'destination' => '600000000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->json('data');
        $this->account('GET', 'referral')->assertJsonPath('data.balance', '0');
        $this->account('POST', 'referral/payouts', [
            'amount' => 30,
            'channel' => 'mobile_money',
            'destination' => '600000000',
        ])->assertUnprocessable();

        $this->operationUser('accountant');
        $payout = $this->op('POST', 'referral/payouts/'.$payout['id'].'/approve', [
            'version' => $payout['version'],
            'note' => 'Referee purchases checked (test).',
        ])
            ->assertOk()
            ->json('data');
        $this->op('POST', 'referral/payouts/'.$payout['id'].'/paid', [
            'version' => $payout['version'],
            'evidence_id' => $this->refundEvidence(),
            'reference' => 'TEST-MOMO-1',
            'paid_at' => now()->subMinute()->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        foreach (
            [
                fn () => DB::table('referral_rewards')->delete(),
                fn () => DB::table('referral_payouts')->update(['amount' => 1]),
                fn () => DB::table('referral_programs')->update(['rate_bp' => 9999]),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('A referral record was rewritten.');
            } catch (QueryException) {
                $this->assertSame(3, DB::table('referral_rewards')->count());
            }
        }
    }

    public function test_shared_contacts_flag_a_referral_for_review_and_customers_with_purchases_cannot_join(): void
    {
        $o = $this->warehouseOrder(1);
        $this->program(100000);
        $this->client($o['client']);
        $code = $this->account('GET', 'referral')->json('data.code');
        // A customer who already paid an order is not a new customer.
        $this->account('POST', 'referral/join', ['code' => $code])->assertUnprocessable();

        // Same phone as the sponsor: allowed, but held for a human review before any reward is settled.
        $phone = DB::table('customer_addresses')->where('user_id', $o['client']->id)->value('contact_phone');
        $referee = $this->client();
        $this->address('Akwa');
        DB::table('customer_addresses')
            ->where('user_id', $referee->id)
            ->update(['contact_phone' => $phone]);
        $this->account('POST', 'referral/join', ['code' => $code])->assertCreated();
        $order = $this->buy($o, $referee);
        $this->delivered($order);
        $this->travel(8)->days();
        $this->artisan('referrals:reconcile')->assertSuccessful();
        $this->assertSame('pending', DB::table('referral_rewards')->value('status'));

        $this->operationUser('support');
        $referral = $this->op('GET', 'referral/referrals?status=flagged')
            ->assertOk()
            ->assertJsonPath('data.0.signals.0', 'shared_phone')
            ->json('data.0');
        $this->op('POST', 'referral/referrals/'.$referral['id'].'/review', [
            'version' => $referral['version'],
            'decision' => 'approve',
            'note' => 'Family members, separate accounts (test).',
        ])->assertForbidden();
        $this->operationUser();
        $this->op('POST', 'referral/referrals/'.$referral['id'].'/review', [
            'version' => $referral['version'],
            'decision' => 'approve',
            'note' => 'Family members, separate accounts (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->artisan('referrals:reconcile')->assertSuccessful();
        $this->assertSame('validated', DB::table('referral_rewards')->value('status'));
        $this->getJson('/api/v1/operations/referral/payouts')->assertOk();
        $this->operationUser('catalogue');
        $this->op('GET', 'referral/referrals')->assertForbidden();
        $this->assertNotNull(User::query()->find($referee->id));
    }
}
