<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Outstanding items are decisions as of one statement, never changes to earlier statements or ledger lines. */
final class TreasuryOutstanding
{
    public function previous(object $statement): ?object
    {
        return DB::table('treasury_statements')
            ->where('book_id', $statement->book_id)
            ->where('treasury_account_id', $statement->treasury_account_id)
            ->where('status', '!=', 'void')
            ->where('ends_on', '<', $statement->starts_on)
            ->orderByDesc('ends_on')
            ->first();
    }

    public function requirePreviousClosed(object $statement): void
    {
        $previous = $this->previous($statement);
        if ($previous && $previous->status !== 'closed') {
            AccountingBooks::fail('status', 'TREASURY_CONTINUITY');
        }
    }

    public function closure(object $statement): ?object
    {
        return DB::table('treasury_closures')
            ->where('statement_id', $statement->id)
            ->where('version', $statement->version)
            ->first();
    }

    public function incoming(object $statement): array
    {
        $previous = $this->previous($statement);
        if (! $previous || $previous->status !== 'closed' || ! ($closure = $this->closure($previous))) {
            return [];
        }

        return json_decode($closure->snapshot, true)['outstanding'];
    }

    public function ids(object $statement, string $side): array
    {
        return array_values(
            array_map(
                fn ($i) => $i['line_id'],
                array_filter($this->incoming($statement), fn ($i) => $i['side'] === $side),
            ),
        );
    }

    public function rows(object $statement)
    {
        return DB::table('treasury_statement_lines')
            ->whereIn(
                'statement_id',
                DB::table('treasury_statements')
                    ->where('book_id', $statement->book_id)
                    ->where('treasury_account_id', $statement->treasury_account_id)
                    ->select('id'),
            )
            ->where(function ($q) use ($statement) {
                $q->where('statement_id', $statement->id)->orWhereIn('id', $this->ids($statement, 'statement'));
            });
    }

    public function active(object $statement)
    {
        return DB::table('treasury_outstanding')->where('statement_id', $statement->id)->where('active_slot', 1);
    }

    public function guardMatching(object $statement, array $rows, array $ledger): void
    {
        $this->requirePreviousClosed($statement);
        if (
            $this->active($statement)
                ->where(fn ($q) => $q->whereIn('statement_line_id', $rows)->orWhereIn('ledger_line_id', $ledger))
                ->exists()
        ) {
            AccountingBooks::fail('lines', 'TREASURY_WITHDRAW_OUTSTANDING');
        }
    }

    private function candidate(object $statement, string $side, string $id): array
    {
        $service = app(TreasuryReconciliation::class);
        if ($side === 'ledger') {
            $row = $service->ledger($statement)->where('l.id', $id)->first();
            if ($row) {
                return $service->ledgerDto($row, $statement);
            }
        } else {
            $row = $this->rows($statement)->where('id', $id)->first();
            if ($row) {
                $dto = $service->rowDto($row);

                return [...$dto, 'net_amount' => $row->direction === 'in' ? (string) $row->amount : '-'.$row->amount];
            }
        }
        AccountingBooks::fail('line_id', 'TREASURY_MATCH_SCOPE');
    }

    private function evidence(string $id): object
    {
        return app(PrivateDocuments::class)->requireClean($id, null, ['accounting_evidence']);
    }

    public function create(object $book, object $statement, array $data, User $actor): array
    {
        app(TreasuryReconciliation::class)->mutable($book, $statement, $data['version']);
        $this->requirePreviousClosed($statement);
        if ($this->active($statement)->count() >= 1000) {
            AccountingBooks::fail('lines', 'TREASURY_OUTSTANDING_LIMIT');
        }
        if ($data['expected_on'] <= $statement->ends_on) {
            AccountingBooks::fail('expected_on', 'TREASURY_EXPECTED_DATE');
        }
        $candidate = $this->candidate($statement, $data['side'], $data['line_id']);
        $data['line_id'] = $candidate['id'];
        $field = $data['side'] === 'ledger' ? 'ledger_line_id' : 'statement_line_id';
        if ($candidate['match_id'] !== null || $this->active($statement)->where($field, $data['line_id'])->exists()) {
            AccountingBooks::fail('line_id', 'TREASURY_ALREADY_MATCHED');
        }
        $document = $this->evidence($data['document_id']);
        $id = Records::id();
        $snapshot = [
            'book_id' => $book->id,
            'statement_id' => $statement->id,
            'statement_sha256' => $statement->sha256,
            'side' => $data['side'],
            'line_id' => $data['line_id'],
            'line' => $candidate,
            'expected_on' => $data['expected_on'],
            'note' => $data['note'],
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'incoming' => array_values(
                array_filter(
                    $this->incoming($statement),
                    fn ($i) => $i['side'] === $data['side'] && $i['line_id'] === $data['line_id'],
                ),
            ),
        ];
        DB::table('treasury_outstanding')->insert([
            'id' => $id,
            'statement_id' => $statement->id,
            $field => $data['line_id'],
            'expected_on' => $data['expected_on'],
            'note' => $data['note'],
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        $this->bump($statement);
        Records::audit($actor, 'treasury.outstanding_created', $id, [
            'statement_id' => $statement->id,
            'note' => $data['note'],
        ]);

        return $this->dto(Records::row('treasury_outstanding', $id, false));
    }

    public function transition(
        object $book,
        object $statement,
        string $id,
        string $action,
        array $data,
        User $actor,
    ): array {
        app(TreasuryReconciliation::class)->mutable($book, $statement, $data['version']);
        $item = Records::row('treasury_outstanding', $id, false);
        abort_unless($item->statement_id === $statement->id, 404);
        if ($item->status === 'void' || ($action === 'review' && $item->status !== 'pending')) {
            AccountingBooks::fail('status', 'TREASURY_STATE');
        }
        if ($action === 'review') {
            $this->requirePreviousClosed($statement);
            if (
                ! hash_equals($item->sha256, $data['sha256']) ||
                ($book->mode === 'live' && (int) $item->created_by === $actor->id)
            ) {
                AccountingBooks::fail('sha256', 'TREASURY_OUTSTANDING_REVIEW');
            }
            $this->validateItem($statement, $item);
            DB::table('treasury_outstanding')
                ->where('id', $id)
                ->update(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        } else {
            DB::table('treasury_outstanding')
                ->where('id', $id)
                ->update(['status' => 'void', 'active_slot' => null]);
        }
        $this->bump($statement);
        Records::audit($actor, 'treasury.outstanding_'.$action, $id, [
            'statement_id' => $statement->id,
            'sha256' => $item->sha256,
            'note' => $data['note'],
        ]);

        return $this->dto(Records::row('treasury_outstanding', $id, false));
    }

    private function bump(object $statement): void
    {
        DB::table('treasury_statements')
            ->where('id', $statement->id)
            ->update(['version' => $statement->version + 1]);
    }

    private function validateItem(object $statement, object $item): void
    {
        $snapshot = json_decode($item->snapshot, true);
        $candidate = $this->candidate($statement, $snapshot['side'], $snapshot['line_id']);
        $document = $this->evidence($item->document_id);
        if (
            $candidate['match_id'] !== null ||
            ! hash_equals($snapshot['document']['sha256'], $document->sha256) ||
            ! hash_equals(
                app(CommandBus::class)->fingerprint($snapshot['line']),
                app(CommandBus::class)->fingerprint($candidate),
            )
        ) {
            AccountingBooks::fail('line_id', 'SOURCE_CHANGED');
        }
    }

    public function forClosure(object $statement): array
    {
        $result = [];
        foreach ($this->active($statement)->orderBy('id')->get() as $item) {
            if ($item->status !== 'approved') {
                AccountingBooks::fail('status', 'TREASURY_OUTSTANDING_REVIEW');
            }
            $this->validateItem($statement, $item);
            $s = json_decode($item->snapshot, true);
            $result[] = [
                'item_id' => $item->id,
                'side' => $s['side'],
                'line_id' => $s['line_id'],
                'net_amount' => $s['line']['net_amount'],
                'expected_on' => $item->expected_on,
                'sha256' => $item->sha256,
                'reviewed_by' => (int) $item->reviewed_by,
                'reviewed_at' => Records::iso($item->reviewed_at),
            ];
        }

        return $result;
    }

    public function saveClosure(
        object $statement,
        array $totals,
        array $outstanding,
        User $actor,
        ?array $balance = null,
    ): void {
        $snapshot = [
            'statement_id' => $statement->id,
            'statement_sha256' => $statement->sha256,
            'version' => (int) $statement->version + 1,
            'balance' => $balance,
            'totals' => $totals,
            'outstanding' => $outstanding,
            'matches' => DB::table('treasury_matches')
                ->where('statement_id', $statement->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => app(TreasuryReconciliation::class)->matchDto($m))
                ->all(),
        ];
        DB::table('treasury_closures')->insert([
            'id' => Records::id(),
            'statement_id' => $statement->id,
            'version' => $statement->version + 1,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    public function guardReopening($later): void
    {
        $ids = (clone $later)->pluck('id')->all();
        if (
            DB::table('treasury_matches')->whereIn('statement_id', $ids)->where('status', 'active')->exists() ||
            DB::table('treasury_outstanding')->whereIn('statement_id', $ids)->where('active_slot', 1)->exists()
        ) {
            AccountingBooks::fail('status', 'TREASURY_SUCCESSOR');
        }
    }

    public function dto(object $row): array
    {
        $s = json_decode($row->snapshot, true);

        return [
            'id' => $row->id,
            'statement_id' => $row->statement_id,
            'side' => $s['side'],
            'line_id' => $s['line_id'],
            'expected_on' => $row->expected_on,
            'note' => $row->note,
            'document_id' => $row->document_id,
            'snapshot' => $s,
            'sha256' => $row->sha256,
            'status' => $row->status,
            'created_by' => (int) $row->created_by,
            'reviewed_by' => $row->reviewed_by === null ? null : (int) $row->reviewed_by,
            'reviewed_at' => $row->reviewed_at ? Records::iso($row->reviewed_at) : null,
        ];
    }

    public function closureDto(object $row): array
    {
        return [
            'id' => $row->id,
            'statement_id' => $row->statement_id,
            'version' => (int) $row->version,
            'snapshot' => json_decode($row->snapshot, true),
            'sha256' => $row->sha256,
            'created_by' => (int) $row->created_by,
            'created_at' => Records::iso($row->created_at),
        ];
    }
}
