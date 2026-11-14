<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Dedicated per-book consumer. Never acknowledges another consumer and never posts an entry. */
final class AccountingCapture
{
    public function settings(object $book): array
    {
        return [
            'version' => (int) $book->capture_version,
            'enabled' => (bool) $book->capture_enabled,
            'from' => $book->capture_from,
            'automatic_posting' => false,
        ];
    }

    public function configure(object $book, array $data, User $actor): array
    {
        abort_unless((int) $book->capture_version === $data['version'], 409);
        $from = $data['from'] ?? $book->capture_from;
        if (($data['enabled'] && ! $from) || ($book->capture_from && $from > $book->capture_from)) {
            AccountingBooks::fail('from', 'CAPTURE_START_INVALID');
        }
        DB::table('accounting_books')
            ->where('id', $book->id)
            ->update([
                'capture_enabled' => $data['enabled'],
                'capture_from' => $from,
                'capture_version' => $book->capture_version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'accounting.capture_configured', $book->id, [
            'enabled' => $data['enabled'],
            'from' => $from,
            'note' => $data['note'],
        ]);

        return $this->settings(Records::row('accounting_books', $book->id, false));
    }

    public function eligible(object $book)
    {
        return DB::table('outbox_events AS e')
            ->leftJoin('accounting_capture_receipts AS r', function ($join) use ($book) {
                $join->on('r.event_id', '=', 'e.id')->where('r.book_id', $book->id);
            })
            ->whereIn('e.type', array_column(AccountingSourceCatalog::FACTS, 2))
            ->where(function ($q) use ($book) {
                $q->whereNull('r.id')
                    ->orWhere(function ($q) {
                        $q->where('r.status', 'retry_pending')->where(
                            fn ($sq) => $sq->whereNull('r.next_attempt_at')->orWhere('r.next_attempt_at', '<=', now()),
                        );
                    })
                    ->orWhere(function ($q) use ($book) {
                        $q->where('r.status', 'excluded')
                            ->where('r.reason', 'BEFORE_CAPTURE_START')
                            ->where('r.policy_version', '<', $book->capture_version);
                    });
            })
            ->select('e.id')
            ->orderBy('e.created_at')
            ->orderBy('e.id');
    }

    public function process(string $bookId, string $eventId): string
    {
        return DB::transaction(function () use ($bookId, $eventId) {
            $book = Records::row('accounting_books', $bookId);
            if (! $book->capture_enabled) {
                return 'skipped';
            }
            $event = DB::table('outbox_events')->where('id', $eventId)->first();
            $type = $event ? app(AccountingSourceCatalog::class)->typeForEvent($event->type) : null;
            if (! $type) {
                return 'unsupported';
            }
            $receipt = DB::table('accounting_capture_receipts')
                ->where('book_id', $bookId)
                ->where('event_id', $eventId)
                ->first();
            if (
                $receipt &&
                ! (
                    $receipt->status === 'retry_pending' ||
                    ($receipt->status === 'excluded' &&
                        $receipt->reason === 'BEFORE_CAPTURE_START' &&
                        (int) $receipt->policy_version < (int) $book->capture_version)
                )
            ) {
                return 'skipped';
            }
            if ($receipt?->next_attempt_at && CarbonImmutable::parse($receipt->next_attempt_at, 'UTC')->isFuture()) {
                return 'skipped';
            }
            $receiptId = $receipt?->id ?? Records::id();
            if (! $receipt) {
                DB::table('accounting_capture_receipts')->insert([
                    'id' => $receiptId,
                    'book_id' => $bookId,
                    'event_id' => $eventId,
                    'source_type' => $type,
                    'status' => 'retry_pending',
                    'policy_version' => $book->capture_version,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $resource = null;
            $date = null;
            $sourceId = null;
            $failures = 0;
            $next = null;
            try {
                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                $v = Validator::make(is_array($payload) ? $payload : [], ['id' => ['required', 'uuid']]);
                if ($v->fails()) {
                    throw new \InvalidArgumentException('INVALID_EVENT');
                }
                $resource = $v->validated()['id'];
                [$table, $field] = AccountingSourceCatalog::FACTS[$type];
                $time = DB::table($table)->where('id', $resource)->value($field);
                $date = $time
                    ? CarbonImmutable::parse($time, 'UTC')->setTimezone('Africa/Douala')->toDateString()
                    : null;
                $result = DB::transaction(function () use ($book, $type, $resource, $eventId) {
                    $snapshot = app(AccountingSourceRegistry::class)->read($type, $resource);
                    if ($snapshot['event_date'] < $book->capture_from) {
                        return ['excluded', 'BEFORE_CAPTURE_START', null];
                    }
                    if ($book->mode === 'live' && $snapshot['demo']) {
                        return ['excluded', 'DEMO_SOURCE', null];
                    }
                    $source = app(AccountingSources::class)->capture($book, $type, $resource, null, $eventId);
                    if (! hash_equals($source['sha256'], app(CommandBus::class)->fingerprint($snapshot))) {
                        return ['review_required', 'SOURCE_CHANGED', $source['id']];
                    }

                    return ['captured', null, $source['id']];
                });
                [$status, $reason, $sourceId] = $result;
            } catch (AccountingSourceRejected $error) {
                $reason = $error->reason;
                $status = in_array($reason, ['TESTMODE_SOURCE', 'CONSIGNMENT_NOT_OWNED', 'NO_ACCEPTED_VALUE'], true)
                    ? 'excluded'
                    : 'review_required';
            } catch (ValidationException $error) {
                $status = 'review_required';
                $reason = 'SOURCE_NOT_ELIGIBLE';
            } catch (\InvalidArgumentException|\JsonException $error) {
                $status = 'review_required';
                $reason = 'INVALID_EVENT';
            } catch (\Throwable $error) {
                if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 404) {
                    $status = 'review_required';
                    $reason = 'SOURCE_MISSING';
                } else {
                    $failures = ($receipt?->consecutive_failures ?? 0) + 1;
                    $status = $failures >= 5 ? 'failed' : 'retry_pending';
                    $reason = 'CAPTURE_FAILED';
                    $next =
                        $status === 'retry_pending' ? now()->addSeconds(min(3600, 60 * 2 ** ($failures - 1))) : null;
                }
            }
            $attempt = ($receipt?->attempts ?? 0) + 1;
            DB::table('accounting_capture_receipts')
                ->where('id', $receiptId)
                ->update([
                    'source_type' => $type,
                    'resource_id' => $resource,
                    'event_date' => $date,
                    'source_id' => $sourceId,
                    'status' => $status,
                    'reason' => $reason,
                    'policy_version' => $book->capture_version,
                    'attempts' => $attempt,
                    'consecutive_failures' => $failures,
                    'next_attempt_at' => $next,
                    'updated_at' => now(),
                ]);
            DB::table('accounting_capture_attempts')->insert([
                'receipt_id' => $receiptId,
                'attempt' => $attempt,
                'status' => $status,
                'reason' => $reason,
                'source_id' => $sourceId,
                'policy_version' => $book->capture_version,
                'created_at' => now(),
            ]);
            Records::audit(null, 'accounting.capture_'.$status, $receiptId, [
                'book_id' => $bookId,
                'event_id' => $eventId,
                'reason' => $reason,
            ]);

            return $status;
        }, 3);
    }

    public function retry(object $book, object $receipt, string $note, User $actor): array
    {
        if ($receipt->status === 'captured') {
            AccountingBooks::fail('receipt', 'CAPTURE_ALREADY_COMPLETED');
        }
        DB::table('accounting_capture_receipts')
            ->where('id', $receipt->id)
            ->update([
                'status' => 'retry_pending',
                'consecutive_failures' => 0,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'accounting.capture_retry', $receipt->id, [
            'book_id' => $book->id,
            'note' => $note,
            'prior_attempts' => $receipt->attempts,
        ]);

        return $this->dto(Records::row('accounting_capture_receipts', $receipt->id, false));
    }

    public function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'event_id' => $r->event_id,
            'source_type' => $r->source_type,
            'resource_id' => $r->resource_id,
            'event_date' => $r->event_date,
            'source_id' => $r->source_id,
            'status' => $r->status,
            'reason' => $r->reason,
            'policy_version' => (int) $r->policy_version,
            'attempts' => (int) $r->attempts,
            'consecutive_failures' => (int) $r->consecutive_failures,
            'next_attempt_at' => Records::iso($r->next_attempt_at),
            'created_at' => Records::iso($r->created_at),
            'updated_at' => Records::iso($r->updated_at),
        ];
    }
}
