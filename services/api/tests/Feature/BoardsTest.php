<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class BoardsTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    private function client(?User $user = null): User
    {
        $user ??= User::factory()->create(['roles' => ['client']]);
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeaders([
            'Origin' => 'http://localhost:4321',
            'Accept' => 'application/json',
        ]);

        return $user;
    }

    private function api(string $method, string $path, array $body = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? $this->fixtureId())->json(
            $method,
            '/api/v1/'.$path,
            $body,
        );
    }

    private function board(string $title = 'Tableau privé de test', string $kind = 'collection'): array
    {
        return $this->api('POST', 'boards', [
            'title' => $title,
            'description' => 'Description de test',
            'kind' => $kind,
            'hide_gift_purchases' => true,
            'visibility' => 'public',
            'owner_id' => 999999,
        ])
            ->assertCreated()
            ->json('data');
    }

    private function getBoard(string $id): array
    {
        return $this->api('GET', 'boards/'.$id)
            ->assertOk()
            ->json('data');
    }

    private function pin(array $board, string $offer, array $extra = []): array
    {
        return $this->api(
            'POST',
            'boards/'.$board['id'].'/pins',
            array_replace(
                [
                    'version' => $board['version'],
                    'offer_id' => $offer,
                    'wished_quantity' => 2,
                    'caption' => 'Légende partageable',
                    'personal_note' => 'NOTE PERSONNELLE PRIVÉE',
                ],
                $extra,
            ),
        )
            ->assertCreated()
            ->json('data');
    }

    private function fixture(): array
    {
        $this->operationUser();

        return $this->fixtureCatalogue();
    }

    private function invite(array $board, User $recipient, string $role = 'viewer'): array
    {
        return $this->api('POST', 'boards/'.$board['id'].'/invitations', [
            'version' => $board['version'],
            'email' => $recipient->email,
            'role' => $role,
            'expires_in_days' => 2,
        ])
            ->assertCreated()
            ->json('data');
    }

    private function accept(array $invite, ?string $key = null)
    {
        return $this->api('POST', substr($invite['url'], 8).'/accept', ['accepted' => true], $key);
    }

    public function test_private_default_and_account_isolation_apply_despite_forged_owner_and_visibility(): void
    {
        $owner = $this->client();
        $board = $this->board();
        $this->assertSame('private', $board['visibility']);
        $this->assertSame('owner', $board['role']);
        $this->assertDatabaseHas('boards', ['id' => $board['id'], 'owner_id' => $owner->id, 'visibility' => 'private']);
        $this->api('GET', 'boards/public')->assertOk()->assertJsonPath('meta.total', 0);
        $this->api('GET', 'boards/public/'.$board['id'])->assertNotFound();
        $this->client();
        $this->api('GET', 'boards')->assertJsonPath('meta.total', 0);
        $this->api('GET', 'boards/'.$board['id'])->assertNotFound();
        $this->api('POST', 'boards/'.$board['id'].'/sections', [
            'version' => 1,
            'title' => 'Forged',
        ])->assertNotFound();
    }

    public function test_sections_saved_prices_personal_notes_duplicates_and_archived_offers_are_handled(): void
    {
        $ctx = $this->fixture();
        $this->client();
        $board = $this->board();
        $board = $this->api('POST', 'boards/'.$board['id'].'/sections', [
            'version' => $board['version'],
            'title' => 'Section test',
        ])
            ->assertCreated()
            ->json('data');
        $sectionId = $board['sections'][0]['id'];
        $result = $this->pin($board, $ctx['offer'], ['section_id' => $sectionId]);
        $board = $result['board'];
        $duplicate = $this->pin($board, $ctx['offer']);
        $this->assertTrue($duplicate['already_pinned']);
        $this->assertDatabaseCount('board_pins', 1);
        $this->assertSame('NOTE PERSONNELLE PRIVÉE', $board['pins'][0]['personal_note']);
        $this->assertSame(1000, $board['pins'][0]['price_at_save']['amount']);
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['selling_price' => 2000]);
        $this->api('GET', 'boards/'.$board['id'])
            ->assertJsonPath('data.pins.0.offer.price.amount', 2000)
            ->assertJsonPath('data.pins.0.price_at_save.amount', 1000);
        $board = $this->api('DELETE', 'boards/'.$board['id'].'/sections/'.$sectionId, [
            'version' => $board['version'],
        ])
            ->assertOk()
            ->assertJsonPath('data.pins.0.section_id', null)
            ->json('data');
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        $this->api('GET', 'boards/'.$board['id'])
            ->assertJsonPath('data.pins.0.offer', null)
            ->assertJsonPath('data.pins.0.saved_name', 'Fixture de test');
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_publication_and_unlisted_links_are_explicit_and_never_include_notes_or_contacts(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $board = $this->pin($this->board(), $ctx['offer'])['board'];
        $this->api('POST', 'boards/'.$board['id'].'/visibility', [
            'version' => $board['version'],
            'visibility' => 'public',
            'reviewed' => false,
        ])->assertUnprocessable();
        $board = $this->api('POST', 'boards/'.$board['id'].'/visibility', [
            'version' => $board['version'],
            'visibility' => 'public',
            'reviewed' => true,
        ])
            ->assertOk()
            ->json('data');
        $public = $this->api('GET', 'boards/public/'.$board['id'])
            ->assertOk()
            ->assertJsonMissingPath('data.pins.0.personal_note')
            ->getContent();
        $this->assertStringNotContainsString('NOTE PERSONNELLE', $public);
        $this->assertStringNotContainsString($owner->email, $public);
        $this->api('GET', 'boards/public')->assertJsonPath('meta.total', 1);
        $share = $this->api('POST', 'boards/'.$board['id'].'/share', [
            'version' => $board['version'],
            'expires_in_days' => 2,
            'reviewed' => true,
        ])
            ->assertCreated()
            ->json('data');
        $this->api('GET', 'boards/public/'.$board['id'])->assertNotFound();
        $this->getJson($share['url'])->assertOk()->assertJsonMissingPath('data.pins.0.personal_note');
        $this->assertStringNotContainsString(
            substr($share['url'], -64),
            json_encode(DB::table('boards')->find($board['id'])),
        );
        $board = $this->api('POST', 'boards/'.$board['id'].'/visibility', [
            'version' => $share['board']['version'],
            'visibility' => 'private',
            'reviewed' => true,
        ])
            ->assertOk()
            ->json('data');
        $this->getJson($share['url'])->assertNotFound();
        $this->api('POST', 'boards/'.$board['id'].'/visibility', [
            'version' => $board['version'],
            'visibility' => 'public',
            'reviewed' => true,
        ])->assertOk();
        $this->getJson($share['url'])->assertNotFound();
    }

    public function test_invitation_requires_the_exact_recipient_and_explicit_acceptance_and_grants_only_viewer_access(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $board = $this->pin($this->board(), $ctx['offer'])['board'];
        $recipient = User::factory()->create(['roles' => ['client']]);
        $count = User::count();
        $invite = $this->invite($board, $recipient);
        $this->assertSame($count, User::count());
        $this->assertFalse($invite['external_message_sent']);
        $this->assertDatabaseCount('board_members', 0);
        $this->client();
        $this->getJson($invite['url'])->assertNotFound();
        $this->accept($invite)->assertNotFound();
        $this->client($recipient);
        $this->getJson($invite['url'])->assertOk()->assertJsonMissingPath('data.pins');
        $this->api('GET', 'boards/'.$board['id'])->assertNotFound();
        $this->api('POST', substr($invite['url'], 8).'/accept', ['accepted' => false])->assertUnprocessable();
        $accepted = $this->accept($invite)
            ->assertOk()
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.pins.0.personal_note', '')
            ->json('data');
        $this->api('POST', 'boards/'.$board['id'].'/sections', [
            'version' => $accepted['version'],
            'title' => 'Forbidden',
        ])->assertForbidden();
        $this->api('POST', 'boards/'.$board['id'].'/invitations', [
            'version' => $accepted['version'],
            'email' => $owner->email,
            'role' => 'editor',
            'expires_in_days' => 2,
        ])->assertForbidden();
        $this->assertSame(['client'], $recipient->fresh()->roles);
    }

    public function test_editor_notes_remain_personal_and_revocation_blocks_reads_writes_and_cached_command_replays(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $board = $this->pin($this->board(), $ctx['offer'])['board'];
        $recipient = User::factory()->create(['roles' => ['client']]);
        $invite = $this->invite($board, $recipient, 'editor');
        $this->client($recipient);
        $acceptKey = $this->fixtureId();
        $board = $this->accept($invite, $acceptKey)->assertOk()->json('data');
        $pinId = $board['pins'][0]['id'];
        $key = $this->fixtureId();
        $body = [
            'version' => $board['version'],
            'section_id' => null,
            'wished_quantity' => 3,
            'caption' => 'Edited caption',
            'personal_note' => 'EDITOR PRIVATE NOTE',
        ];
        $board = $this->api('PUT', 'boards/'.$board['id'].'/pins/'.$pinId, $body, $key)
            ->assertOk()
            ->assertJsonPath('data.pins.0.personal_note', 'EDITOR PRIVATE NOTE')
            ->json('data');
        $this->client($owner);
        $this->api('GET', 'boards/'.$board['id'])->assertJsonPath(
            'data.pins.0.personal_note',
            'NOTE PERSONNELLE PRIVÉE',
        );
        $this->api('PUT', 'boards/'.$board['id'].'/members/'.$recipient->id, [
            'version' => $board['version'],
            'role' => null,
        ])->assertOk();
        $this->client($recipient);
        $this->api('GET', 'boards/'.$board['id'])->assertNotFound();
        $this->api('PUT', 'boards/'.$board['id'].'/pins/'.$pinId, $body, $key)->assertNotFound();
        $this->accept($invite, $acceptKey)->assertNotFound();
        $this->api('GET', 'boards')->assertJsonPath('meta.total', 0);
    }

    public function test_expired_invitation_and_revoked_link_cannot_restore_a_member_and_editor_cannot_publish(): void
    {
        $this->client();
        $owner = auth()->user();
        $board = $this->board();
        $recipient = User::factory()->create(['roles' => ['client']]);
        $invite = $this->invite($board, $recipient, 'editor');
        $board = $this->getBoard($board['id']);
        $this->client($recipient);
        $boardAccepted = $this->accept($invite)->assertOk()->json('data');
        $this->api('POST', 'boards/'.$board['id'].'/visibility', [
            'version' => $boardAccepted['version'],
            'visibility' => 'public',
            'reviewed' => true,
        ])->assertForbidden();
        $this->client($owner);
        $board = $this->getBoard($board['id']);
        $second = $this->invite($board, $recipient, 'editor');
        $board = $this->getBoard($board['id']);
        $this->api('PUT', 'boards/'.$board['id'].'/members/'.$recipient->id, [
            'version' => $board['version'],
            'role' => null,
        ])->assertOk();
        $this->client($recipient);
        $this->accept($second)->assertNotFound();
        $this->client($owner);
        $board = $this->getBoard($board['id']);
        $third = $this->invite($board, $recipient);
        $this->travel(3)->days();
        $this->client($recipient);
        $this->getJson($third['url'])->assertNotFound();
    }

    public function test_copy_and_move_are_versioned_and_private_notes_are_not_copied_implicitly(): void
    {
        $ctx = $this->fixture();
        $this->client();
        $source = $this->pin($this->board('Source'), $ctx['offer'])['board'];
        $target = $this->board('Target');
        $data = [
            'source_version' => $source['version'],
            'target_version' => $target['version'],
            'target_board_id' => $target['id'],
            'section_id' => null,
            'copy' => true,
            'copy_personal_note' => false,
        ];
        $copy = $this->api('POST', 'boards/'.$source['id'].'/pins/'.$source['pins'][0]['id'].'/transfer', $data)
            ->assertOk()
            ->assertJsonPath('data.target.pins.0.personal_note', '')
            ->json('data.target');
        $this->api(
            'POST',
            'boards/'.$source['id'].'/pins/'.$source['pins'][0]['id'].'/transfer',
            $data,
        )->assertConflict();
        $this->assertCount(1, $this->getBoard($source['id'])['pins']);
        $third = $this->board('Move target');
        $data['target_board_id'] = $third['id'];
        $data['target_version'] = $third['version'];
        $data['copy'] = false;
        $moved = $this->api(
            'POST',
            'boards/'.$source['id'].'/pins/'.$source['pins'][0]['id'].'/transfer',
            $data,
        )
            ->assertOk()
            ->json('data');
        $this->assertCount(0, $this->getBoard($source['id'])['pins']);
        $this->assertSame($source['pins'][0]['id'], $moved['pin_id']);
    }

    public function test_selected_pins_are_repriced_into_own_cart_and_collection_fees_remain_unknown_until_a_real_quote(): void
    {
        $ctx = $this->fixture();
        $this->client();
        $board = $this->pin($this->board('Cadeaux', 'gift'), $ctx['offer'])['board'];
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['selling_price' => 3333]);
        $cart = $this->api('GET', 'cart')->assertOk()->json('data');
        $key = $this->fixtureId();
        $data = ['cart_version' => $cart['version'], 'pin_ids' => [$board['pins'][0]['id']]];
        $this->api('POST', 'boards/'.$board['id'].'/to-cart', $data, $key)
            ->assertOk()
            ->assertJsonPath('data.cart.selected_subtotal', '6666')
            ->assertJsonPath('data.collection_fees', null)
            ->assertJsonPath('data.delivery_quote_required', true);
        $this->api('POST', 'boards/'.$board['id'].'/to-cart', $data, $key)
            ->assertOk()
            ->assertJsonPath('data.cart.lines.0.quantity', 2);
        $this->assertFalse($board['purchase_tracking_available']);
        $this->assertNull($board['pins'][0]['purchased_quantity']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_cart_to_board_move_is_atomic_and_keeps_other_lines_out_of_the_operation(): void
    {
        $ctx = $this->fixture();
        $this->client();
        $board = $this->board();
        $cart = $this->api('GET', 'cart')->json('data');
        $cart = $this->api('POST', 'cart/lines', [
            'version' => $cart['version'],
            'offer_id' => $ctx['offer'],
            'quantity' => 4,
            'selected' => false,
        ])
            ->assertCreated()
            ->json('data');
        $result = $this->api('POST', 'boards/'.$board['id'].'/from-cart', [
            'version' => $board['version'],
            'cart_version' => $cart['version'],
            'line_id' => $cart['lines'][0]['id'],
            'section_id' => null,
            'copy' => false,
            'merge_quantity' => false,
        ])
            ->assertOk()
            ->assertJsonCount(0, 'data.cart.lines')
            ->assertJsonPath('data.board.pins.0.wished_quantity', 4)
            ->json('data');
        $this->assertFalse($result['copied']);
        $this->assertSame('private', $result['board']['visibility']);
    }

    public function test_section_reorder_checks_membership_and_stale_versions_and_archive_disables_public_access(): void
    {
        $this->client();
        $board = $this->board();
        $board = $this->api('POST', 'boards/'.$board['id'].'/sections', [
            'version' => $board['version'],
            'title' => 'A',
        ])
            ->assertCreated()
            ->json('data');
        $board = $this->api('POST', 'boards/'.$board['id'].'/sections', [
            'version' => $board['version'],
            'title' => 'B',
        ])
            ->assertCreated()
            ->json('data');
        $ids = array_reverse(array_column($board['sections'], 'id'));
        $this->api('POST', 'boards/'.$board['id'].'/sections/reorder', [
            'version' => $board['version'],
            'ids' => [$ids[0]],
        ])->assertUnprocessable();
        $board = $this->api('POST', 'boards/'.$board['id'].'/sections/reorder', [
            'version' => $board['version'],
            'ids' => $ids,
        ])
            ->assertOk()
            ->assertJsonPath('data.sections.0.id', $ids[0])
            ->json('data');
        $this->api('PUT', 'boards/'.$board['id'], [
            'version' => 1,
            'title' => 'Stale',
            'description' => '',
            'cover_pin_id' => null,
        ])->assertConflict();
        $key = $this->fixtureId();
        $body = ['version' => $board['version']];
        $this->api('POST', 'boards/'.$board['id'].'/archive', $body, $key)->assertOk();
        $this->api('POST', 'boards/'.$board['id'].'/archive', $body, $key)->assertOk();
        $this->api('GET', 'boards/'.$board['id'])->assertNotFound();
        $this->api('GET', 'boards/public/'.$board['id'])->assertNotFound();
    }

    public function test_reader_copy_cannot_copy_another_persons_note_and_cannot_move_their_pin(): void
    {
        $ctx = $this->fixture();
        $owner = $this->client();
        $source = $this->pin($this->board(), $ctx['offer'])['board'];
        $recipient = User::factory()->create(['roles' => ['client']]);
        $invite = $this->invite($source, $recipient);
        $this->client($recipient);
        $source = $this->accept($invite)->assertOk()->json('data');
        $target = $this->board('Reader owned board');
        $data = [
            'source_version' => $source['version'],
            'target_version' => $target['version'],
            'target_board_id' => $target['id'],
            'section_id' => null,
            'copy' => false,
            'copy_personal_note' => true,
        ];
        $path = 'boards/'.$source['id'].'/pins/'.$source['pins'][0]['id'].'/transfer';
        $this->api('POST', $path, $data)->assertForbidden();
        $data['copy'] = true;
        $this->api('POST', $path, $data)->assertOk()->assertJsonPath('data.target.pins.0.personal_note', '');
        $this->assertDatabaseCount('board_pin_notes', 1);
        $this->api('POST', 'boards/'.$source['id'].'/gift-settings', [
            'version' => $source['version'],
            'kind' => 'gift',
            'gift_date' => null,
            'hide_gift_purchases' => false,
        ])->assertForbidden();
    }
}
