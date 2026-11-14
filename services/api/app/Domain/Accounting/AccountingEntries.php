<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All mutations run under the book lock, shared with chart imports and period closing. */
final class AccountingEntries
{
    private function party(array $party, string $required): array
    {
        if ($required === 'none' || ($party['type'] ?? null) !== $required) {
            AccountingBooks::fail('party', 'PARTY_INVALID');
        }
        $key = $party['id'] ?? '';
        $name = null;
        if ($required === 'supplier' && str_starts_with($key, 'supplier:') && Str::isUuid(substr($key, 9))) {
            $name = DB::table('suppliers')->where('id', substr($key, 9))->value('name');
        } elseif (
            $required === 'customer' &&
            preg_match('/^user:([1-9][0-9]*)$/', $key, $m) &&
            filter_var($m[1], FILTER_VALIDATE_INT)
        ) {
            $name = DB::table('users')->where('id', $m[1])->value('name');
        } elseif ($required === 'customer' && str_starts_with($key, 'professional:') && Str::isUuid(substr($key, 13))) {
            $identity = DB::table('professional_profiles')->where('id', substr($key, 13))->value('billing_identity');
            $name = $identity ? json_decode($identity, true)['legal_name'] : null;
        }
        if ($name === null) {
            AccountingBooks::fail('party', 'PARTY_INVALID');
        }

        return ['type' => $required, 'id' => $key, 'name' => $name];
    }

    private function normalize(object $book, array $lines): array
    {
        $result = [];
        $debits = [];
        $credits = [];
        foreach ($lines as $line) {
            $account = app(AccountingBooks::class)->row('accounting_accounts', $line['account_id'], $book);
            if (! $account->active || ! $account->postable) {
                AccountingBooks::fail('account_id', 'ACCOUNT_NOT_POSTABLE');
            }
            $debit = Money::parse($line['debit']);
            $credit = Money::parse($line['credit']);
            if (($debit === 0) === ($credit === 0)) {
                AccountingBooks::fail('lines', 'LINE_INVALID');
            }
            $party = $line['party'] ?? null;
            if ($account->auxiliary_type !== 'none') {
                $party = $this->party($party ?? [], $account->auxiliary_type);
            } elseif ($party !== null) {
                AccountingBooks::fail('party', 'PARTY_INVALID');
            }
            $result[] = [
                'account_id' => $account->id,
                'account' => app(AccountingBooks::class)->account($account),
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'party' => $party,
            ];
            $debits[] = $debit;
            $credits[] = $credit;
        }
        Money::sum($debits);
        Money::sum($credits);

        return $result;
    }

    public function create(
        object $book,
        array $data,
        User $actor,
        ?object $source = null,
        ?string $mapping = null,
    ): array {
        $period = app(AccountingCalendar::class)->period($book, $data['entry_date']);
        $journal = app(AccountingBooks::class)->row('accounting_journals', $data['journal_id'], $book);
        if (! $journal->active || (int) $book->chart_version < 1) {
            AccountingBooks::fail('journal_id', 'CONFIGURATION_REQUIRED');
        }
        $lines = $this->normalize($book, $data['lines']);
        $id = Records::id();
        DB::table('accounting_entries')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'journal_id' => $journal->id,
            'year_id' => $period->year_id,
            'period_id' => $period->id,
            'source_id' => $source?->id,
            'mapping_id' => $mapping,
            'chart_version' => $book->chart_version,
            'entry_date' => $data['entry_date'],
            'description' => Records::json($data['description']),
            'reference' => $data['reference'],
            'document_ids' => Records::json($data['document_ids'] ?? []),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->replaceLines($id, $lines);

        return $this->record($id, 'created', $actor);
    }

    public function update(
        object $book,
        object $entry,
        array $data,
        User $actor,
        bool $sourceRefresh = false,
        ?string $mappingId = null,
    ): array {
        abort_unless((int) $entry->version === $data['version'], 409);
        if ($entry->status !== 'draft' || ($entry->source_id && ! $sourceRefresh)) {
            AccountingBooks::fail('entry', 'ENTRY_IMMUTABLE');
        }
        $period = app(AccountingCalendar::class)->period($book, $data['entry_date']);
        $journal = app(AccountingBooks::class)->row('accounting_journals', $data['journal_id'], $book);
        if (! $journal->active) {
            AccountingBooks::fail('journal_id', 'CONFIGURATION_REQUIRED');
        }
        $lines = $this->normalize($book, $data['lines']);
        DB::table('accounting_entries')
            ->where('id', $entry->id)
            ->update([
                'journal_id' => $journal->id,
                'period_id' => $period->id,
                'year_id' => $period->year_id,
                'chart_version' => $book->chart_version,
                'entry_date' => $data['entry_date'],
                'description' => Records::json($data['description']),
                'reference' => $data['reference'],
                'document_ids' => Records::json($data['document_ids'] ?? []),
                ...$sourceRefresh ? ['mapping_id' => $mappingId] : [],
                'version' => $entry->version + 1,
                'updated_at' => now(),
            ]);
        $this->replaceLines($entry->id, $lines);

        return $this->record($entry->id, 'updated', $actor);
    }

    private function replaceLines(string $entryId, array $lines): void
    {
        DB::table('accounting_entry_lines')->where('entry_id', $entryId)->delete();
        foreach ($lines as $position => $line) {
            DB::table('accounting_entry_lines')->insert([
                'entry_id' => $entryId,
                'position' => $position + 1,
                'account_id' => $line['account_id'],
                'account_snapshot' => Records::json($line['account']),
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'party' => $line['party'] === null ? null : Records::json($line['party']),
            ]);
        }
    }

    public function post(object $book, object $entry, int $version, User $actor): array
    {
        abort_unless((int) $entry->version === $version, 409);
        if ($entry->status !== 'draft') {
            AccountingBooks::fail('entry', 'ENTRY_IMMUTABLE');
        }
        $period = app(AccountingCalendar::class)->period($book, $entry->entry_date);
        app(TreasuryReconciliation::class)->guardPosting($book, $entry);
        if (
            $period->id !== $entry->period_id ||
            (! $entry->reversal_of && (int) $entry->chart_version !== (int) $book->chart_version)
        ) {
            AccountingBooks::fail('entry', 'CHART_CHANGED');
        }
        if (
            $book->mode === 'live' &&
            ((int) $book->reviewed_chart_version !== (int) $book->chart_version ||
                $book->reviewed_chart_version === null)
        ) {
            AccountingBooks::fail('chart', 'REVIEW_REQUIRED');
        }
        $journal = app(AccountingBooks::class)->row('accounting_journals', $entry->journal_id, $book);
        if (! $journal->active) {
            AccountingBooks::fail('journal', 'CONFIGURATION_REQUIRED');
        }
        $view = $this->dto($entry);
        if (
            count(array_unique(array_column($view['lines'], 'account_id'))) < 2 ||
            $view['debit_total'] === '0' ||
            ! $view['balanced']
        ) {
            AccountingBooks::fail('lines', 'ENTRY_UNBALANCED');
        }
        if (! $entry->reversal_of) {
            $this->normalize($book, $view['lines']);
        }
        $documents = [];
        foreach (json_decode($entry->document_ids, true) as $documentId) {
            $document = Records::row('private_documents', $documentId, false);
            app(PrivateDocuments::class)->authorize($actor, $document->purpose, false);
            app(PrivateDocuments::class)->requireClean($documentId, $document->supplier_id, [$document->purpose]);
            $documents[] = ['id' => $documentId, 'sha256' => $document->sha256];
        }
        if ($book->mode === 'live' && ! $entry->source_id && ! $entry->reversal_of && ! $documents) {
            AccountingBooks::fail('document_ids', 'EVIDENCE_REQUIRED');
        }
        if ($entry->source_id) {
            app(AccountingSources::class)->validatePosting($book, $entry);
        }
        $lastDate = DB::table('accounting_entries')
            ->where('book_id', $book->id)
            ->where('journal_id', $entry->journal_id)
            ->where('year_id', $entry->year_id)
            ->where('status', 'posted')
            ->max('entry_date');
        if ($lastDate && $entry->entry_date < $lastDate) {
            AccountingBooks::fail('entry_date', 'JOURNAL_CHRONOLOGY');
        }
        $year = Records::row('accounting_years', $entry->year_id, false);
        // Separate composite sequence: sales document keys are intentionally limited to 40 characters.
        $sequenceKey = ['book_id' => $book->id, 'year_id' => $entry->year_id, 'journal_id' => $entry->journal_id];
        $sequence = DB::table('accounting_sequences')->where($sequenceKey)->first();
        $next = $sequence ? (int) $sequence->last_number + 1 : 1;
        DB::table('accounting_sequences')->updateOrInsert($sequenceKey, ['last_number' => $next]);
        $number =
            ($book->mode === 'demo' ? 'DEMO-' : '').
            $journal->code.
            '-'.
            str_replace('-', '', $year->starts_on).
            '-'.
            str_pad((string) $next, 8, '0', STR_PAD_LEFT);
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'number' => $number,
            'journal' => app(AccountingBooks::class)->journalDto($journal),
            'year_id' => $entry->year_id,
            'period_id' => $entry->period_id,
            'entry_date' => $entry->entry_date,
            'description' => $view['description'],
            'reference' => $entry->reference,
            'documents' => $documents,
            'source_id' => $entry->source_id,
            'mapping_id' => $entry->mapping_id,
            'reversal_of' => $entry->reversal_of,
            'chart_version' => (int) $entry->chart_version,
            'lines' => $view['lines'],
            'debit_total' => $view['debit_total'],
            'credit_total' => $view['credit_total'],
        ];
        DB::table('accounting_entries')
            ->where('id', $entry->id)
            ->update([
                'status' => 'posted',
                'number' => $number,
                'posted_snapshot' => Records::json($snapshot),
                'sha256' => app(CommandBus::class)->fingerprint($snapshot),
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
        if ($entry->source_id) {
            DB::table('accounting_sources')
                ->where('id', $entry->source_id)
                ->update(['posted_entry_id' => $entry->id]);
        }

        return $this->record($entry->id, 'posted', $actor);
    }

    public function cancel(object $entry, array $data, User $actor): array
    {
        abort_unless((int) $entry->version === $data['version'], 409);
        if ($entry->status !== 'draft') {
            AccountingBooks::fail('entry', 'ENTRY_IMMUTABLE');
        }
        DB::table('accounting_entries')
            ->where('id', $entry->id)
            ->update(['status' => 'cancelled', 'version' => $entry->version + 1, 'updated_at' => now()]);
        Records::audit($actor, 'accounting.draft_cancelled', $entry->id, ['note' => $data['note']]);

        return $this->record($entry->id, 'cancelled', $actor);
    }

    public function reverse(object $book, object $original, array $data, User $actor): array
    {
        app(ExpensePlans::class)->guardReversal($original);
        app(Withdrawals::class)->guardReversal($original);
        app(Financings::class)->guardReversal($original);
        abort_unless((int) $original->version === $data['version'], 409);
        if (
            $original->status !== 'posted' ||
            DB::table('accounting_entries')->where('reversal_of', $original->id)->exists()
        ) {
            AccountingBooks::fail('entry', 'REVERSAL_NOT_AVAILABLE');
        }
        if ($data['entry_date'] < $original->entry_date) {
            AccountingBooks::fail('entry_date', 'JOURNAL_CHRONOLOGY');
        }
        $period = app(AccountingCalendar::class)->period($book, $data['entry_date']);
        $journal = app(AccountingBooks::class)->row('accounting_journals', $data['journal_id'], $book);
        $originalView = $this->dto($original);
        $id = Records::id();
        DB::table('accounting_entries')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'journal_id' => $journal->id,
            'year_id' => $period->year_id,
            'period_id' => $period->id,
            'reversal_of' => $original->id,
            'chart_version' => $original->chart_version,
            'entry_date' => $data['entry_date'],
            'description' => Records::json($data['description']),
            'reference' => 'REV '.$original->number,
            'document_ids' => '[]',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lines = array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $originalView['lines']);
        $this->replaceLines($id, $lines);
        $this->record($id, 'created', $actor);
        Records::audit($actor, 'accounting.reversal_requested', $id, [
            'original_id' => $original->id,
            'note' => $data['note'],
        ]);

        return $this->post($book, Records::row('accounting_entries', $id, false), 1, $actor);
    }

    private function record(string $id, string $action, User $actor): array
    {
        $entry = Records::row('accounting_entries', $id, false);
        $view = $this->dto($entry);
        DB::table('accounting_entry_revisions')->insert([
            'entry_id' => $id,
            'version' => $entry->version,
            'action' => $action,
            'snapshot' => Records::json($view),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'accounting.entry_'.$action, $id, ['version' => $entry->version]);

        return $view;
    }

    public function dto(object $entry): array
    {
        $lines = DB::table('accounting_entry_lines')
            ->where('entry_id', $entry->id)
            ->orderBy('position')
            ->get()
            ->map(
                fn ($l) => [
                    'account_id' => $l->account_id,
                    'account' => json_decode($l->account_snapshot, true),
                    'debit' => (string) $l->debit,
                    'credit' => (string) $l->credit,
                    'party' => $l->party ? json_decode($l->party, true) : null,
                ],
            )
            ->all();
        $debit = Money::sum(array_map(fn ($l) => (int) $l['debit'], $lines));
        $credit = Money::sum(array_map(fn ($l) => (int) $l['credit'], $lines));

        return [
            'id' => $entry->id,
            'book_id' => $entry->book_id,
            'journal_id' => $entry->journal_id,
            'year_id' => $entry->year_id,
            'period_id' => $entry->period_id,
            'source_id' => $entry->source_id,
            'mapping_id' => $entry->mapping_id,
            'reversal_of' => $entry->reversal_of,
            'chart_version' => (int) $entry->chart_version,
            'status' => $entry->status,
            'version' => (int) $entry->version,
            'number' => $entry->number,
            'entry_date' => $entry->entry_date,
            'description' => json_decode($entry->description, true),
            'reference' => $entry->reference,
            'document_ids' => json_decode($entry->document_ids, true),
            'lines' => $lines,
            'debit_total' => (string) $debit,
            'credit_total' => (string) $credit,
            'balanced' => $debit > 0 && $debit === $credit,
            'sha256' => $entry->sha256,
            'posted_at' => Records::iso($entry->posted_at),
            'reversals' => DB::table('accounting_entries')->where('reversal_of', $entry->id)->pluck('id')->all(),
        ];
    }
}
