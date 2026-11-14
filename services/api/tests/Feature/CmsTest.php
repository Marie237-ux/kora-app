<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class CmsTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Africa/Douala'));
    }

    private function media(bool $public = true): string
    {
        $id = (string) Str::uuid();
        DB::table('media_assets')->insert([
            'id' => $id,
            'uploaded_by' => User::factory()->create()->id,
            'disk' => 'local',
            'object_key' => 'test/'.$id.'.webp',
            'mime' => 'image/webp',
            'width' => 1200,
            'height' => 800,
            'bytes' => 1000,
            'sha256' => str_repeat('a', 64),
            'alt' => json_encode(['fr' => 'Image de test', 'en' => 'Test image']),
            'is_public' => $public,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function staff(User $user): void
    {
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withSession([
            'mfa_verified' => true,
            'auth.password_confirmed_at' => now()->timestamp,
        ]);
    }

    private function settings(array $theme = []): array
    {
        return [
            'company' => [
                'name' => 'KORA (test)',
                'tagline' => ['fr' => 'Boutique de test', 'en' => 'Test shop'],
                'phone' => '+237 600 00 00 00',
                'email' => 'contact@example.test',
                'address' => 'Akwa, Douala (test)',
                'hours' => ['fr' => '8h-18h', 'en' => '8am-6pm'],
            ],
            'theme' => array_replace_recursive(
                [
                    'light' => [
                        'primary' => '#0B5D3B',
                        'on_primary' => '#FFFFFF',
                        'background' => '#FFFFFF',
                        'text' => '#111111',
                        'accent' => '#E0A100',
                    ],
                    'dark' => [
                        'primary' => '#7FD1A8',
                        'on_primary' => '#0A0A0A',
                        'background' => '#101010',
                        'text' => '#F2F2F2',
                        'accent' => '#FFC94D',
                    ],
                ],
                $theme,
            ),
            'fonts' => ['heading' => 'kora-display', 'body' => 'kora-text'],
            'menus' => [
                'header' => [['label' => ['fr' => 'Boutiques', 'en' => 'Shops'], 'href' => '/shops']],
                'footer' => [],
            ],
            'social' => [['network' => 'facebook', 'url' => 'https://example.test/kora']],
        ];
    }

    public function test_pages_are_versioned_validated_scheduled_and_rolled_back(): void
    {
        $owner = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $this->getJson('/api/v1/content/pages/home')
            ->assertOk()
            ->assertJsonPath('data.page', null)
            ->assertJsonPath('data.settings', null);
        $this->getJson('/api/v1/content/pages/promo')->assertNotFound();

        // Unreadable colours, unknown fonts, markup and unknown blocks never reach a draft.
        $this->op('POST', 'cms/settings/site/drafts', [
            'content' => $this->settings(['light' => ['text' => '#EEEEEE']]),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content.theme.light.text', 'error.fields');
        $bad = $this->settings();
        $bad['fonts']['body'] = 'comic-sans';
        $bad['company']['name'] = '<script>alert(1)</script>';
        $this->op('POST', 'cms/settings/site/drafts', ['content' => $bad])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.fonts.body', 'content.company.name'], 'error.fields');
        $this->op('POST', 'cms/page/home/drafts', ['content' => ['blocks' => [['type' => 'html', 'code' => '<div>']]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content.blocks.0.type', 'error.fields');
        $this->op('POST', 'cms/page/home/drafts', [
            'content' => [
                'blocks' => [
                    [
                        'type' => 'banner',
                        'media_id' => $this->media(),
                        'text' => ['fr' => 'Promo', 'en' => 'Sale'],
                        'link' => 'javascript:alert(1)',
                    ],
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content.blocks.0.link', 'error.fields');
        $this->op('POST', 'cms/settings/other/drafts', ['content' => $this->settings()])->assertNotFound();

        $settings = $this->op('POST', 'cms/settings/site/drafts', ['content' => $this->settings()])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'cms/versions/'.$settings['id'].'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $private = $this->media(false);
        $blocks = fn (string $title) => [
            'blocks' => [
                [
                    'type' => 'product_selection',
                    'title' => ['fr' => $title, 'en' => $title],
                    'product_ids' => [$ctx['product']],
                ],
                [
                    'type' => 'image_text',
                    'media_id' => $private,
                    'title' => ['fr' => 'Livraison', 'en' => 'Delivery'],
                    'text' => ['fr' => 'Partout à Douala.', 'en' => 'All over Douala.'],
                    'link' => '/delivery',
                ],
                [
                    'type' => 'faq',
                    'items' => [
                        ['q' => ['fr' => 'Délai ?', 'en' => 'When?'], 'a' => ['fr' => '24 h.', 'en' => '24 h.']],
                    ],
                ],
            ],
        ];
        $v1 = $this->op('POST', 'cms/page/home/drafts', [
            'title' => ['fr' => 'Accueil', 'en' => 'Home'],
            'content' => $blocks('Sélection 1'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->json('data');
        // A private media cannot be shown publicly: publication waits until the media is public.
        $this->op('POST', 'cms/versions/'.$v1['id'].'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content', 'error.fields');
        DB::table('media_assets')
            ->where('id', $private)
            ->update(['is_public' => true]);

        // The draft preview is for the team, not indexed, and the public site still shows nothing.
        $this->getJson('/api/v1/operations/cms/preview/'.$v1['preview_token'])
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.status', 'draft');
        $this->op('POST', 'cms/versions/'.$v1['id'].'/publish')->assertOk();
        $revision = $this->getJson('/api/v1/content/revision')->json('data.revision');

        $this->app['auth']->forgetGuards();
        $page = $this->getJson('/api/v1/content/pages/home?locale=fr')->assertOk()->json('data');
        $this->assertSame('KORA (test)', $page['settings']['company']['name']);
        $this->assertStringContainsString(
            'X-Kora-Locale',
            (string) $this->getJson('/api/v1/content/pages/home')->headers->get('Vary'),
        );
        $this->assertSame(1, $page['page']['version']);
        $this->assertCount(3, $page['page']['blocks']);
        $this->assertSame('/api/v1/media/'.$private.'/public', $page['media'][$private]);
        $this->getJson('/api/v1/operations/cms/preview/'.$v1['preview_token'])->assertUnauthorized();

        // Once published, a version's content cannot be rewritten nor deleted, even directly in the database.
        foreach (
            [
                fn () => DB::table('cms_versions')
                    ->where('id', $v1['id'])
                    ->update(['content' => '{"blocks":[]}']),
                fn () => DB::table('cms_versions')->where('id', $v1['id'])->delete(),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('A published version must stay as published.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('CMS_IMMUTABLE', $e->getMessage());
            }
        }

        // Version 2 scheduled for tomorrow 06:00 Douala: today the site keeps version 1, tomorrow it switches alone.
        $this->operationUser();
        $v2 = $this->op('POST', 'cms/page/home/drafts', ['content' => $blocks('Sélection 2')])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'cms/versions/'.$v2['id'].'/publish', ['publish_at' => '2026-11-13T06:00:00+01:00'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
        $this->op('POST', 'cms/versions/'.$v2['id'].'/publish')->assertUnprocessable();
        $state = $this->getJson('/api/v1/content/revision')->json('data');
        $this->assertGreaterThan($revision, $state['revision']);
        $this->assertSame('2026-11-13T05:00:00+00:00', $state['next_change_at']);
        $this->assertSame(1, $this->getJson('/api/v1/content/pages/home')->json('data.page.version'));
        $this->travelTo(CarbonImmutable::parse('2026-11-13 06:00:00', 'Africa/Douala'));
        $this->assertSame(2, $this->getJson('/api/v1/content/pages/home')->json('data.page.version'));
        $this->assertSame(
            'Sélection 2',
            $this->getJson('/api/v1/content/pages/home')->json('data.page.blocks.0.title.fr'),
        );

        // A withdrawn product leaves the selection (empty block hidden); a revoked media loses its public address.
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update(['status' => 'draft']);
        DB::table('media_assets')
            ->where('id', $private)
            ->update(['revoked' => true]);
        $page = $this->getJson('/api/v1/content/pages/home')->json('data');
        $this->assertSame(['image_text', 'faq'], array_column($page['page']['blocks'], 'type'));
        $this->assertSame([], $page['media']);
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update(['status' => 'published']);
        DB::table('media_assets')
            ->where('id', $private)
            ->update(['revoked' => false]);

        // Rollback: version 1's content comes back as version 3; history keeps all three.
        $v3 = $this->op('POST', 'cms/versions/'.$v1['id'].'/rollback')
            ->assertCreated()
            ->assertJsonPath('data.number', 3)
            ->json('data');
        $this->assertSame('published', $v3['status']);
        $this->assertSame(
            'Sélection 1',
            $this->getJson('/api/v1/content/pages/home')->json('data.page.blocks.0.title.fr'),
        );
        $document = DB::table('cms_documents')->where('slug', 'home')->value('id');
        $this->op('GET', 'cms/documents/'.$document.'/versions')
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->op('GET', 'cms/documents?kind=page')->assertOk()->assertJsonPath('data.0.live_number', 3);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'cms.published')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cms.scheduled')->count());

        // A manager reads content but cannot publish it.
        $this->operationUser('manager');
        $this->op('POST', 'cms/page/home/drafts', ['content' => $blocks('X')])->assertForbidden();
        $this->op('POST', 'cms/versions/'.$v1['id'].'/rollback')->assertForbidden();
        $this->assertSame(
            (string) $owner->id,
            (string) DB::table('cms_versions')->where('number', 1)->value('author_id'),
        );
    }

    public function test_campaigns_override_the_theme_by_priority_and_end_by_themselves(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $settings = $this->op('POST', 'cms/settings/site/drafts', ['content' => $this->settings()])->json('data');
        $this->op('POST', 'cms/versions/'.$settings['id'].'/publish')->assertOk();
        $campaign = fn (array $overrides) => [
            'name' => 'Black Friday (test)',
            'starts_at' => '2026-11-12T00:00:00+01:00',
            'ends_at' => '2026-11-15T00:00:00+01:00',
            'priority' => 10,
            'locales' => ['fr', 'en'],
            'pages' => ['home'],
            'theme' => ['light' => ['primary' => '#000000', 'on_primary' => '#FFD400']],
            'banner' => ['fr' => 'Black Friday : -20 %', 'en' => 'Black Friday: 20% off'],
            'product_ids' => [$ctx['product']],
            ...$overrides,
        ];
        // A campaign colour unreadable on the base theme is refused, as are inverted dates and missing banners.
        $this->op(
            'POST',
            'cms/campaigns',
            $campaign(['theme' => ['light' => ['on_primary' => '#111111', 'primary' => '#000000']]]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme.light.on_primary', 'error.fields');
        $this->op('POST', 'cms/campaigns', $campaign(['ends_at' => '2026-11-11T00:00:00+01:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at', 'error.fields');
        $this->op('POST', 'cms/campaigns', $campaign(['banner' => ['fr' => 'Promo', 'en' => '']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('banner.en', 'error.fields');

        $black = $this->op('POST', 'cms/campaigns', $campaign([]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $christmas = $this->op(
            'POST',
            'cms/campaigns',
            $campaign([
                'name' => 'Noël (test)',
                'priority' => 20,
                'locales' => ['fr'],
                'theme' => ['light' => ['accent' => '#C00000']],
                'banner' => ['fr' => 'Noël arrive', 'en' => null],
            ]),
        )
            ->assertCreated()
            ->json('data');
        // Not active yet: the base configuration.
        $this->assertNull($this->getJson('/api/v1/content/pages/home')->json('data.campaign'));
        $black = $this->op('POST', 'cms/campaigns/'.$black['id'].'/status', [
            'status' => 'active',
            'version' => $black['version'],
        ])
            ->assertOk()
            ->json('data');
        $this->op('POST', 'cms/campaigns/'.$christmas['id'].'/status', [
            'status' => 'active',
            'version' => $christmas['version'],
        ])->assertOk();
        $this->op('POST', 'cms/campaigns/'.$black['id'].'/status', [
            'status' => 'paused',
            'version' => 1,
        ])->assertStatus(409);

        // French visitors see the higher priority campaign; English visitors, the one in their language.
        $fr = $this->getJson('/api/v1/content/pages/home?locale=fr')->json('data');
        $this->assertSame('Noël arrive', $fr['campaign']['banner']);
        $this->assertSame('#C00000', $fr['settings']['theme']['light']['accent']);
        $this->assertSame('#0B5D3B', $fr['settings']['theme']['light']['primary']);
        $en = $this->getJson('/api/v1/content/pages/home?locale=en')->json('data');
        $this->assertSame('Black Friday: 20% off', $en['campaign']['banner']);
        $this->assertSame('#000000', $en['settings']['theme']['light']['primary']);
        $this->assertSame('#F2F2F2', $en['settings']['theme']['dark']['text']);
        $this->assertSame([$ctx['product']], $en['campaign']['product_ids']);
        $this->assertSame(
            '2026-11-14T23:00:00+00:00',
            $this->getJson('/api/v1/content/revision')->json('data.next_change_at'),
        );

        // At the end of the period the base theme comes back without any action.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 00:00:00', 'Africa/Douala'));
        $after = $this->getJson('/api/v1/content/pages/home?locale=en')->json('data');
        $this->assertNull($after['campaign']);
        $this->assertSame('#0B5D3B', $after['settings']['theme']['light']['primary']);
    }

    public function test_legal_versions_are_published_carefully_accepted_once_and_kept_with_orders(): void
    {
        $author = $this->operationUser();
        $draft = $this->op('POST', 'cms/legal/terms_of_sale/drafts', [
            'body' => ['fr' => 'Conditions de vente de test.', 'en' => null],
            'effective_at' => '2026-11-12T00:00:00+01:00',
            'requires_acceptance' => true,
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'cms/legal/unknown/drafts', [
            'body' => ['fr' => 'x', 'en' => 'x'],
            'effective_at' => '2026-11-12',
            'requires_acceptance' => false,
        ])->assertNotFound();
        // Both languages before publication.
        $this->op('POST', 'cms/legal/'.$draft['id'].'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body.en', 'error.fields');
        $draft = $this->op('POST', 'cms/legal/terms_of_sale/drafts', [
            'body' => ['fr' => 'Conditions de vente de test.', 'en' => 'Test terms of sale.'],
            'effective_at' => '2026-11-12T00:00:00+01:00',
            'requires_acceptance' => true,
        ])
            ->assertJsonPath('data.number', 2)
            ->json('data');

        // Publishing needs its own right and a recent identity confirmation.
        $this->operationUser('catalogue');
        $this->op('POST', 'cms/legal/'.$draft['id'].'/publish')->assertForbidden();
        $this->staff($author);
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('POST', 'cms/legal/'.$draft['id'].'/publish')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        // With the four-eyes rule, the author cannot publish their own text.
        config(['kora.cms_four_eyes' => true]);
        $this->op('POST', 'cms/legal/'.$draft['id'].'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version', 'error.fields');
        $this->operationUser();
        $published = $this->op('POST', 'cms/legal/'.$draft['id'].'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->json('data');
        try {
            DB::transaction(
                fn () => DB::table('legal_versions')
                    ->where('id', $published['id'])
                    ->update(['body' => '{"fr":"x","en":"x"}']),
            );
            $this->fail('A published legal text must not change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('CMS_IMMUTABLE', $e->getMessage());
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/legal/terms_of_sale')
            ->assertOk()
            ->assertJsonPath('data.number', 2)
            ->assertJsonCount(1, 'meta.history');
        $this->getJson('/api/v1/legal/privacy')->assertNotFound();
        $this->getJson('/api/v1/legal/versions/'.$draft['id'])->assertOk();

        // The order keeps the terms in force when it was placed, and an explicit acceptance is recorded once.
        $this->operationUser();
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $this->assertSame($published['id'], DB::table('orders')->where('id', $order['id'])->value('terms_version_id'));
        $this->getJson('/api/v1/account/legal/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'terms_of_sale');
        $accept = ['version_id' => $published['id'], 'context' => 'order', 'order_id' => $order['id']];
        $this->postJson('/api/v1/account/legal/acceptances', $accept)->assertCreated();
        $this->postJson('/api/v1/account/legal/acceptances', $accept)->assertCreated();
        $this->assertSame(1, DB::table('legal_acceptances')->count());
        $this->getJson('/api/v1/account/legal/pending')->assertJsonCount(0, 'data');
        // Another customer cannot attach an acceptance to someone else's order, nor accept a draft.
        $this->client();
        $this->postJson('/api/v1/account/legal/acceptances', $accept)->assertNotFound();
        $this->postJson('/api/v1/account/legal/acceptances', [
            'version_id' => DB::table('legal_versions')->where('number', 1)->value('id'),
            'context' => 'update',
        ])->assertUnprocessable();
        try {
            DB::transaction(fn () => DB::table('legal_acceptances')->delete());
            $this->fail('Acceptances are kept.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('CMS_IMMUTABLE', $e->getMessage());
        }

        // A new version later never rewrites what was accepted, and asks again from its effective date.
        $this->operationUser();
        config(['kora.cms_four_eyes' => false]);
        $v3 = $this->op('POST', 'cms/legal/terms_of_sale/drafts', [
            'body' => ['fr' => 'Nouvelles conditions de test.', 'en' => 'New test terms.'],
            'effective_at' => '2026-12-01T00:00:00+01:00',
            'requires_acceptance' => true,
        ])->json('data');
        $this->op('POST', 'cms/legal/'.$v3['id'].'/publish')->assertOk();
        $this->assertSame(2, $this->getJson('/api/v1/legal/terms_of_sale')->json('data.number'));
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:00', 'Africa/Douala'));
        $this->assertSame(3, $this->getJson('/api/v1/legal/terms_of_sale')->json('data.number'));
        $this->client($c['client']);
        $this->getJson('/api/v1/account/legal/pending')->assertJsonPath('data.0.number', 3);
        $this->assertSame($published['id'], DB::table('legal_acceptances')->value('version_id'));
    }
}
