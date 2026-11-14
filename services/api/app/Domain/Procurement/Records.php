<?php

namespace App\Domain\Procurement;

use App\Domain\Shared\BusinessEvents;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Records
{
    public static function id(): string
    {
        return (string) Str::uuid();
    }

    public static function json(mixed $data): string
    {
        return app(CommandBus::class)->json($data);
    }

    public static function row(string $table, string $id, bool $lock = true): object
    {
        $query = DB::table($table)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row, 404);

        return $row;
    }

    public static function supplier(string $id): object
    {
        return self::row('suppliers', $id);
    }

    public static function audit(?User $actor, string $action, string $id, array $details = []): void
    {
        if (! $actor) {
            DB::table('audit_events')->insert([
                'actor_id' => null,
                'action' => $action,
                'resource_id' => $id,
                'details' => self::json($details),
                'created_at' => now(),
            ]);

            return;
        }
        app(BusinessEvents::class)->audit($actor, $action, $id, $details);
    }

    public static function event(string $type, string $id, array $payload): void
    {
        app(BusinessEvents::class)->outbox($type, $type.':'.$id, $payload);
    }

    /** Notifications of one verified fact can be recovered or replayed without rewriting the original event. */
    public static function eventOnce(string $type, string $id, array $payload): int
    {
        return DB::table('outbox_events')->insertOrIgnore([
            'id' => self::id(),
            'type' => $type,
            'deduplication_key' => $type.':'.$id,
            'payload' => self::json($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function iso(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }
}
