<?php

namespace App\Domain\Shared;

use App\Domain\Procurement\Records;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxProcessor
{
    public function __construct(private LocalEventHandlers $handlers) {}

    public function eligible()
    {
        return DB::table('outbox_events')
            ->whereIn('type', LocalEventHandlers::types())
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    /** All supported effects are local DB writes, committed atomically with the receipt. */
    public function process(string $id): string
    {
        try {
            return DB::transaction(function () use ($id) {
                $event = DB::table('outbox_events')->where('id', $id)->lockForUpdate()->first();
                if (! $event || ! in_array($event->type, LocalEventHandlers::types(), true)) {
                    return 'unsupported';
                }
                if (
                    $event->processed_at ||
                    $event->failed_at ||
                    ($event->next_attempt_at && CarbonImmutable::parse($event->next_attempt_at, 'UTC')->isFuture())
                ) {
                    return 'skipped';
                }
                $result = $this->handlers->handle($event);
                $this->attempt($event, $result);
                DB::table('outbox_events')
                    ->where('id', $id)
                    ->update([
                        'processed_at' => now(),
                        'attempts' => $event->attempts + 1,
                        'consecutive_failures' => 0,
                        'last_error_code' => null,
                        'next_attempt_at' => null,
                        'updated_at' => now(),
                    ]);

                return $result;
            }, 3);
        } catch (\Throwable $error) {
            $code =
                $error instanceof \InvalidArgumentException || $error instanceof \JsonException
                    ? 'INVALID_PAYLOAD'
                    : 'HANDLER_FAILED';
            DB::transaction(function () use ($id, $code) {
                $event = DB::table('outbox_events')->where('id', $id)->lockForUpdate()->first();
                // A second worker may already have completed it after our rollback.
                if (! $event || $event->processed_at || $event->failed_at) {
                    return;
                }
                $failures = (int) $event->consecutive_failures + 1;
                $this->attempt($event, 'failed', $code);
                DB::table('outbox_events')
                    ->where('id', $id)
                    ->update([
                        'attempts' => $event->attempts + 1,
                        'consecutive_failures' => $failures,
                        'last_error_code' => $code,
                        'failed_at' => $failures >= 5 ? now() : null,
                        'next_attempt_at' => $failures >= 5 ? null : now()->addSeconds(min(3600, 60 * 2 ** min(6, $failures - 1))),
                        'updated_at' => now(),
                    ]);
            }, 3);
            Log::warning('Outbox handler failed', ['event_id' => $id, 'error_code' => $code]);

            return 'failed';
        }
    }

    private function attempt(object $event, string $result, ?string $code = null): void
    {
        DB::table('outbox_attempts')->insert([
            'id' => Records::id(),
            'event_id' => $event->id,
            'attempt' => $event->attempts + 1,
            'handler' => LocalEventHandlers::name($event->type),
            'result' => $result,
            'error_code' => $code,
            'created_at' => now(),
        ]);
    }

    public function retry(string $id, string $reason, User $actor): array
    {
        $event = Records::row('outbox_events', $id);
        abort_unless(
            $event->failed_at && ! $event->processed_at && in_array($event->type, LocalEventHandlers::types(), true),
            409,
        );
        DB::table('outbox_events')
            ->where('id', $id)
            ->update([
                'failed_at' => null,
                'next_attempt_at' => now(),
                'consecutive_failures' => 0,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'outbox.retry_requested', $id, [
            'reason' => $reason,
            'prior_attempts' => (int) $event->attempts,
        ]);

        return ['id' => $id, 'retry_requested' => true, 'prior_attempts' => (int) $event->attempts];
    }

    public function dto(object $r): array
    {
        $supported = in_array($r->type, LocalEventHandlers::types(), true);

        return [
            'id' => $r->id,
            'type' => $r->type,
            'supported' => $supported,
            'state' => $r->processed_at
                ? 'processed'
                : ($r->failed_at
                    ? 'failed'
                    : ($supported
                        ? 'pending'
                        : 'unsupported')),
            'attempts' => (int) $r->attempts,
            'consecutive_failures' => (int) $r->consecutive_failures,
            'last_error_code' => $r->last_error_code,
            'next_attempt_at' => Records::iso($r->next_attempt_at),
            'failed_at' => Records::iso($r->failed_at),
            'processed_at' => Records::iso($r->processed_at),
            'created_at' => Records::iso($r->created_at),
        ];
    }
}
