<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountingSources
{
    public function mapping(object $book, array $data, User $actor): array
    {
        $latest = $this->currentMapping($book, $data['source_type']);
        abort_unless(($latest ? (int) $latest->version : 0) === $data['version'], 409);
        app(AccountingBooks::class)->row('accounting_journals', $data['journal_id'], $book);
        $allowed = app(AccountingSourceRegistry::class)->measures($data['source_type']);
        foreach ($data['lines'] as $line) {
            if (! in_array($line['measure'], $allowed, true)) {
                AccountingBooks::fail('lines', 'MEASURE_INVALID');
            }
            $account = app(AccountingBooks::class)->row('accounting_accounts', $line['account_id'], $book);
            if (! $account->active || ! $account->postable) {
                AccountingBooks::fail('lines', 'ACCOUNT_NOT_POSTABLE');
            }
        }
        if (count(array_unique(array_column($data['lines'], 'side'))) !== 2) {
            AccountingBooks::fail('lines', 'ENTRY_UNBALANCED');
        }
        $version = ($latest?->version ?? 0) + 1;
        $snapshot = [
            'chart_version' => (int) $book->chart_version,
            'journal_id' => $data['journal_id'],
            'enabled' => $data['enabled'],
            'lines' => $data['lines'],
        ];
        $id = Records::id();
        DB::table('accounting_mappings')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'source_type' => $data['source_type'],
            'version' => $version,
            ...$snapshot,
            'lines' => Records::json($data['lines']),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'note' => $data['note'],
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'accounting.mapping_created', $id, ['version' => $version, 'note' => $data['note']]);

        return $this->mappingDto(Records::row('accounting_mappings', $id, false));
    }

    public function currentMapping(object $book, string $type): ?object
    {
        return DB::table('accounting_mappings')
            ->where('book_id', $book->id)
            ->where('source_type', $type)
            ->orderByDesc('version')
            ->first();
    }

    public function review(object $book, string $id, array $data, User $actor): array
    {
        $mapping = app(AccountingBooks::class)->row('accounting_mappings', $id, $book);
        abort_unless(
            (int) $mapping->chart_version === (int) $book->chart_version &&
                hash_equals($mapping->sha256, $data['sha256']) &&
                $this->currentMapping($book, $mapping->source_type)?->id === $mapping->id,
            409,
        );
        if ($mapping->reviewed_at) {
            AccountingBooks::fail('mapping', 'ALREADY_REVIEWED');
        }
        DB::table('accounting_mappings')
            ->where('id', $id)
            ->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['note']]);
        Records::audit($actor, 'accounting.mapping_reviewed', $id, ['note' => $data['note']]);

        return $this->mappingDto(Records::row('accounting_mappings', $id, false));
    }

    public function capture(
        object $book,
        string $type,
        string $resourceId,
        ?User $actor,
        ?string $eventId = null,
    ): array {
        $existing = DB::table('accounting_sources')
            ->where('book_id', $book->id)
            ->where('source_type', $type)
            ->where('resource_id', $resourceId)
            ->first();
        if ($existing) {
            return $this->dto($existing);
        }
        $snapshot = app(AccountingSourceRegistry::class)->read($type, $resourceId);
        if ($book->mode === 'live' && $snapshot['demo']) {
            AccountingBooks::fail('resource_id', 'DEMO_SOURCE');
        }
        $id = Records::id();
        DB::table('accounting_sources')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'source_type' => $type,
            'resource_id' => $resourceId,
            'occurred_at' => $snapshot['occurred_at'],
            'event_date' => $snapshot['event_date'],
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor?->id,
            'capture_event_id' => $eventId,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'accounting.source_captured', $id, [
            'capture_event_id' => $eventId,
            'source_type' => $type,
            'resource_id' => $resourceId,
        ]);

        return $this->dto(Records::row('accounting_sources', $id, false));
    }

    public function prepare(object $book, object $source, array $data, User $actor): array
    {
        if ($source->posted_entry_id) {
            return app(AccountingEntries::class)->dto(
                Records::row('accounting_entries', $source->posted_entry_id, false),
            );
        }
        $mapping = $this->currentMapping($book, $source->source_type);
        if (! $mapping || ! $mapping->enabled || (int) $mapping->chart_version !== (int) $book->chart_version) {
            AccountingBooks::fail('mapping', 'MAPPING_REQUIRED');
        }
        $snapshot = json_decode($source->snapshot, true);
        if (! hash_equals($source->sha256, app(CommandBus::class)->fingerprint($snapshot))) {
            AccountingBooks::fail('source', 'SOURCE_CHANGED');
        }
        $date = $data['entry_date'] ?? $source->event_date;
        if ($date !== $source->event_date && empty(trim($data['note'] ?? ''))) {
            AccountingBooks::fail('note', 'DATE_OVERRIDE_REASON');
        }
        $lines = [];
        foreach (json_decode($mapping->lines, true) as $rule) {
            $amount = Money::parse($snapshot['measures'][$rule['measure']]);
            if ($amount === 0) {
                continue;
            }
            $account = app(AccountingBooks::class)->row('accounting_accounts', $rule['account_id'], $book);
            $lines[] = [
                'account_id' => $account->id,
                'debit' => $rule['side'] === 'debit' ? (string) $amount : '0',
                'credit' => $rule['side'] === 'credit' ? (string) $amount : '0',
                'party' => $account->auxiliary_type === 'none' ? null : $snapshot['party'],
            ];
        }
        if (! $lines) {
            AccountingBooks::fail('lines', 'ENTRY_UNBALANCED');
        }
        $entryData = [
            'journal_id' => $mapping->journal_id,
            'entry_date' => $date,
            'document_ids' => $snapshot['document_ids'],
            'description' => [
                'fr' => 'Source '.$source->source_type.' — '.$snapshot['reference'],
                'en' => 'Source '.$source->source_type.' — '.$snapshot['reference'],
            ],
            'reference' => mb_substr($snapshot['reference'], 0, 200),
            'lines' => $lines,
        ];
        $latest = $source->latest_entry_id ? Records::row('accounting_entries', $source->latest_entry_id, false) : null;
        abort_unless(($latest ? (int) $latest->version : null) === ($data['entry_version'] ?? null), 409);
        $service = app(AccountingEntries::class);
        $view =
            $latest && $latest->status === 'draft'
                ? $service->update(
                    $book,
                    $latest,
                    [...$entryData, 'version' => (int) $latest->version],
                    $actor,
                    true,
                    $mapping->id,
                )
                : $service->create($book, $entryData, $actor, $source, $mapping->id);
        DB::table('accounting_sources')
            ->where('id', $source->id)
            ->update(['latest_entry_id' => $view['id']]);
        Records::audit($actor, 'accounting.source_prepared', $source->id, [
            'entry_id' => $view['id'],
            'entry_date' => $date,
            'note' => $data['note'] ?? null,
        ]);

        return $view;
    }

    public function validatePosting(object $book, object $entry): void
    {
        $source = app(AccountingBooks::class)->row('accounting_sources', $entry->source_id, $book);
        if ($source->posted_entry_id) {
            AccountingBooks::fail('source', 'SOURCE_ALREADY_POSTED');
        }
        $current = app(AccountingSourceRegistry::class)->read($source->source_type, $source->resource_id);
        if (
            ! hash_equals($source->sha256, app(CommandBus::class)->fingerprint($current)) ||
            ! hash_equals($source->sha256, app(CommandBus::class)->fingerprint(json_decode($source->snapshot, true)))
        ) {
            AccountingBooks::fail('source', 'SOURCE_CHANGED');
        }
        $mapping = $this->currentMapping($book, $source->source_type);
        if (
            ! $mapping ||
            ! $mapping->enabled ||
            $mapping->id !== $entry->mapping_id ||
            (int) $mapping->chart_version !== (int) $book->chart_version
        ) {
            AccountingBooks::fail('mapping', 'MAPPING_REQUIRED');
        }
        if ($book->mode === 'live' && ! $mapping->reviewed_at) {
            AccountingBooks::fail('mapping', 'REVIEW_REQUIRED');
        }
    }

    public function dto(object $s): array
    {
        return [
            'id' => $s->id,
            'source_type' => $s->source_type,
            'resource_id' => $s->resource_id,
            'event_date' => $s->event_date,
            'snapshot' => json_decode($s->snapshot, true),
            'sha256' => $s->sha256,
            'latest_entry_id' => $s->latest_entry_id,
            'posted_entry_id' => $s->posted_entry_id,
            'occurred_at' => Records::iso($s->occurred_at),
            'created_by' => $s->created_by === null ? null : (int) $s->created_by,
            'capture_event_id' => $s->capture_event_id,
        ];
    }

    public function mappingDto(object $m): array
    {
        return [
            'id' => $m->id,
            'source_type' => $m->source_type,
            'version' => (int) $m->version,
            'chart_version' => (int) $m->chart_version,
            'journal_id' => $m->journal_id,
            'enabled' => (bool) $m->enabled,
            'lines' => json_decode($m->lines, true),
            'sha256' => $m->sha256,
            'note' => $m->note,
            'reviewed_at' => Records::iso($m->reviewed_at),
            'reviewed_by' => $m->reviewed_by === null ? null : (int) $m->reviewed_by,
        ];
    }
}
