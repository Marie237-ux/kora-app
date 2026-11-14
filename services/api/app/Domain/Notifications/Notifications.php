<?php

namespace App\Domain\Notifications;

use App\Domain\Procurement\Records;
use Illuminate\Support\Facades\DB;

/**
 * Notification centre (§26). Each notice is written in the user's saved language and links to a resource they
 * may open. Transactional notices always reach the in-app centre; reminders and campaigns follow the user's
 * preferences, campaigns being opt-in. Other channels are recorded honestly: a channel that is not configured is
 * "skipped_not_configured", never shown as sent.
 */
final class Notifications
{
    public const CHANNELS = ['in_app', 'email', 'web_push', 'whatsapp'];

    public const CATEGORIES = ['transactional', 'reminder', 'marketing'];

    /** Defaults before the user says otherwise: reminders on in the centre and by e-mail, campaigns off. */
    private const DEFAULTS = [
        'reminder' => ['in_app' => true, 'email' => true, 'web_push' => true, 'whatsapp' => false],
        'marketing' => ['in_app' => false, 'email' => false, 'web_push' => false, 'whatsapp' => false],
    ];

    public function enabled(int $userId, string $channel, string $category): bool
    {
        if ($category === 'transactional') {
            return true;
        }
        $saved = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('category', $category)
            ->value('enabled');

        return $saved === null ? self::DEFAULTS[$category][$channel] : (bool) $saved;
    }

    /**
     * Creates the notice once per dedupe key. $texts holds fr and en versions: ['fr' => [title, body], ...].
     * Returns null when the user turned this category off in the centre.
     */
    public function notify(
        int $userId,
        string $category,
        string $kind,
        string $dedupeKey,
        array $texts,
        ?string $link,
        bool $sensitive = false,
    ): ?string {
        if (! $this->enabled($userId, 'in_app', $category)) {
            return null;
        }
        $locale = DB::table('users')->where('id', $userId)->value('locale') === 'en' ? 'en' : 'fr';
        [$title, $body] = $texts[$locale];
        $id = Records::id();
        $inserted = DB::table('user_notifications')->insertOrIgnore([
            'id' => $id,
            'user_id' => $userId,
            'category' => $category,
            'kind' => $kind,
            'dedupe_key' => $dedupeKey,
            'locale' => $locale,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'sensitive' => $sensitive,
            'created_at' => now(),
        ]);
        if (! $inserted) {
            return DB::table('user_notifications')
                ->where('user_id', $userId)
                ->where('dedupe_key', $dedupeKey)
                ->value('id');
        }
        foreach (['email', 'web_push', 'whatsapp'] as $channel) {
            DB::table('notification_deliveries')->insert([
                'id' => Records::id(),
                'notification_id' => $id,
                'channel' => $channel,
                'status' => match (true) {
                    ! $this->enabled($userId, $channel, $category) => 'skipped_preference',
                    ! $this->configured($channel) => 'skipped_not_configured',
                    default => 'pending',
                },
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** No provider is connected yet: pending deliveries wait for a real sender, nothing is faked. */
    private function configured(string $channel): bool
    {
        return (bool) config('kora.channels.'.$channel, false);
    }

    public function preferences(int $userId): array
    {
        $out = [];
        foreach (['reminder', 'marketing'] as $category) {
            foreach (self::CHANNELS as $channel) {
                $out[$category][$channel] = $this->enabled($userId, $channel, $category);
            }
        }
        $out['transactional'] = array_fill_keys(self::CHANNELS, true);

        return $out;
    }

    public function setPreferences(int $userId, array $changes): array
    {
        foreach ($changes as $category => $channels) {
            foreach ($channels as $channel => $enabled) {
                DB::table('notification_preferences')->updateOrInsert(
                    ['user_id' => $userId, 'channel' => $channel, 'category' => $category],
                    ['enabled' => (bool) $enabled, 'updated_at' => now()],
                );
            }
        }

        return $this->preferences($userId);
    }

    /** Signed one-click unsubscribe link for an optional channel and category; works without signing in. */
    public function unsubscribeToken(int $userId, string $channel, string $category): string
    {
        return substr(
            hash_hmac(
                'sha256',
                'unsubscribe|'.$userId.'|'.$channel.'|'.$category,
                (string) config('app.key'),
            ),
            0,
            40,
        );
    }

    public function unsubscribe(int $userId, string $channel, string $category, string $token): bool
    {
        if (
            $category === 'transactional' ||
            ! hash_equals($this->unsubscribeToken($userId, $channel, $category), $token)
        ) {
            return false;
        }
        $this->setPreferences($userId, [$category => [$channel => false]]);

        return true;
    }

    public function dto(object $n): array
    {
        return [
            'id' => $n->id,
            'category' => $n->category,
            'kind' => $n->kind,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'sensitive' => (bool) $n->sensitive,
            'read_at' => Records::iso($n->read_at),
            'created_at' => Records::iso($n->created_at),
            'deliveries' => DB::table('notification_deliveries')
                ->where('notification_id', $n->id)
                ->orderBy('channel')
                ->get(['channel', 'status'])
                ->map(fn ($d) => ['channel' => $d->channel, 'status' => $d->status])
                ->values(),
        ];
    }
}
