<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class ConversationsTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, PreparedOrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function api2(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->json($method, '/api/v1/'.$path, $data);
    }

    public function test_conversations_stay_private_sync_without_duplicates_and_become_cases(): void
    {
        $o = $this->warehouseOrder(1);
        $this->readyOrder($o['order'], 1);
        // Fixture shortcut: the courier handover writes this date; the case only reads it.
        DB::table('orders')->update(['delivered_at' => now()]);
        $customer = $o['client'];
        DB::table('users')
            ->where('id', $customer->id)
            ->update(['locale' => 'en']);
        $this->client($customer);
        $this->api2('POST', 'account/conversations', [
            'subject' => 'Order question',
            'context_type' => 'order',
            'context_id' => $this->fixtureId(),
            'body' => 'Hello',
            'client_ref' => 'device-1',
        ])->assertUnprocessable();
        $conversation = $this->api2('POST', 'account/conversations', [
            'subject' => 'Order question',
            'context_type' => 'order',
            'context_id' => $o['order']['id'],
            'body' => 'When will my order arrive? (test)',
            'client_ref' => 'device-1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.last_seq', 1)
            ->json('data');
        // The same message sent twice after a lost connection is stored once.
        foreach (range(1, 2) as $attempt) {
            $this->api2('POST', 'account/conversations/'.$conversation['id'].'/messages', [
                'body' => 'Any news?',
                'client_ref' => 'device-2',
            ])
                ->assertCreated()
                ->assertJsonPath('data.seq', 2);
        }
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/account/conversations/'.$conversation['id'].'/attachments', [
                'file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n% Test only.\n%%EOF"),
                'client_ref' => 'device-3',
            ])
            ->assertCreated();

        $agent = $this->operationUser('support');
        $this->api2('POST', 'operations/quick-replies', [
            'title' => 'Delay',
            'body' => ['fr' => 'Votre commande arrive demain.', 'en' => 'Your order arrives tomorrow.'],
        ])->assertCreated();
        $quick = DB::table('quick_replies')->value('id');
        $this->api2('GET', 'operations/conversations')->assertOk()->assertJsonPath('data.0.unread', 3);
        $this->api2('POST', 'operations/conversations/'.$conversation['id'].'/messages', [
            'body' => 'Courier delayed by rain (internal test).',
            'visibility' => 'internal',
            'client_ref' => 'agent-1',
        ])->assertCreated();
        $this->api2('POST', 'operations/conversations/'.$conversation['id'].'/messages', [
            'quick_reply_id' => $quick,
            'visibility' => 'public',
            'client_ref' => 'agent-2',
        ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Your order arrives tomorrow.');
        $staffView = $this->api2('GET', 'operations/conversations/'.$conversation['id'].'/messages')
            ->assertOk()
            ->json('data');
        $this->assertCount(5, $staffView);
        $this->get(
            '/api/v1/operations/conversations/'.$conversation['id'].'/attachments/'.$staffView[2]['id'],
        )->assertOk();
        $this->api2('POST', 'operations/conversations/'.$conversation['id'].'/assign', [
            'assignee_id' => $agent->id,
        ])->assertOk();

        // The customer never receives the internal note; syncing after the last number returns nothing new.
        $this->client($customer);
        $mine = $this->api2('GET', 'account/conversations/'.$conversation['id'].'/messages')
            ->assertOk()
            ->json();
        $this->assertSame([1, 2, 3, 5], array_column($mine['data'], 'seq'));
        $this->assertStringNotContainsString('internal test', json_encode($mine));
        $this->assertSame(1, $mine['meta']['unread']);
        $this->api2('GET', 'account/conversations/'.$conversation['id'].'/messages?after=5')->assertJsonCount(
            0,
            'data',
        );
        $this->api2('POST', 'account/conversations/'.$conversation['id'].'/read', ['seq' => 5])->assertJsonPath(
            'data.unread',
            0,
        );
        $this->get(
            '/api/v1/account/conversations/'.$conversation['id'].'/attachments/'.$mine['data'][2]['id'],
        )->assertOk();
        $notice = $this->api2('GET', 'account/inbox')->json('data.0');
        $this->assertTrue($notice['sensitive']);
        $this->assertSame('New message from KORA', $notice['title']);
        $this->assertStringNotContainsString('tomorrow', $notice['body']);

        // Another customer reaches nothing, not even through a copied attachment link.
        $this->client();
        $this->api2('GET', 'account/conversations/'.$conversation['id'].'/messages')->assertNotFound();
        $this->get(
            '/api/v1/account/conversations/'.$conversation['id'].'/attachments/'.$mine['data'][2]['id'],
        )->assertNotFound();
        $this->api2('GET', 'account/conversations')->assertJsonCount(0, 'data');

        $this->operationUser('support');
        $this->api2('POST', 'operations/conversations/'.$conversation['id'].'/conversion', [
            'order_line_id' => $o['order']['lines'][0]['id'],
            'kind' => 'damaged',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Box damaged, reported in chat (test).',
        ])->assertOk();
        $this->assertSame($customer->id, (int) DB::table('after_sales_cases')->value('user_id'));
        $this->api2('POST', 'operations/conversations/'.$conversation['id'].'/conversion', [
            'order_line_id' => $o['order']['lines'][0]['id'],
            'kind' => 'damaged',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Twice',
        ])->assertUnprocessable();
        $this->operationUser('catalogue');
        $this->api2('GET', 'operations/conversations')->assertForbidden();
    }

    public function test_the_whatsapp_link_needs_a_configured_number_and_the_customer_consent(): void
    {
        $this->client();
        $this->api2('GET', 'account/whatsapp-link?consent=1')->assertOk()->assertJsonPath('data.available', false);
        config(['kora.whatsapp_number' => '+237 600 00 00 00']);
        $this->api2('GET', 'account/whatsapp-link?reference=CMD-0000001')->assertUnprocessable();
        $link = $this->api2('GET', 'account/whatsapp-link?consent=1&reference=CMD-0000001')
            ->assertOk()
            ->assertJsonPath('data.history_synced', false)
            ->json('data.url');
        $this->assertStringStartsWith('https://wa.me/237600000000?text=', $link);
        $this->assertStringContainsString('CMD-0000001', urldecode($link));
    }
}
