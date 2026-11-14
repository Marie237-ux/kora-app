<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/** Mutations hold the book lock. Lettering allocates posted lines; it never pays a business debt. */
final class AccountingLettering
{
    private function date(object $book, string $date): void
    {
        app(AccountingCalendar::class)->period($book, $date);
        if (
            DB::table('accounting_letterings')
                ->where('book_id', $book->id)
                ->where(fn ($q) => $q->where('matched_on', '>', $date)->orWhere('voided_on', '>', $date))
                ->exists()
        ) {
            AccountingBooks::fail('date', 'LETTERING_CHRONOLOGY');
        }
    }

    private function allocations(?string $asOf = null)
    {
        return DB::table('accounting_lettering_lines as a')
            ->join('accounting_letterings as g', 'g.id', '=', 'a.lettering_id')
            ->when(
                $asOf === null,
                fn ($q) => $q->where('g.status', 'active'),
                fn ($q) => $q
                    ->where('g.matched_on', '<=', $asOf)
                    ->whereIn('g.status', ['active', 'void'])
                    ->where(fn ($v) => $v->whereNull('g.voided_on')->orWhere('g.voided_on', '>', $asOf)),
            )
            ->groupBy('a.ledger_line_id')
            ->selectRaw('a.ledger_line_id, SUM(a.amount) as allocated');
    }

    public function create(object $book, array $data, User $actor): array
    {
        $this->date($book, $data['matched_on']);
        $document = app(PrivateDocuments::class)->requireClean($data['document_id'], null, ['accounting_evidence']);
        $items = $data['lines'];
        usort($items, fn ($a, $b) => $a['ledger_line_id'] <=> $b['ledger_line_id']);
        $rows = [];
        $accountId = null;
        $party = null;
        $debit = BigInteger::zero();
        $credit = BigInteger::zero();
        foreach ($items as $item) {
            $line = DB::table('accounting_entry_lines as l')
                ->join('accounting_entries as e', 'e.id', '=', 'l.entry_id')
                ->where('l.id', $item['ledger_line_id'])
                ->where('e.book_id', $book->id)
                ->select('l.*', 'e.status as entry_status', 'e.entry_date', 'e.number', 'e.sha256 as entry_sha256')
                ->first();
            abort_unless($line, 404);
            $account = json_decode($line->account_snapshot, true);
            $lineParty = $line->party ? json_decode($line->party, true) : null;
            if (
                $line->entry_status !== 'posted' ||
                ! $lineParty ||
                ! in_array($account['auxiliary_type'], ['customer', 'supplier'], true) ||
                $lineParty['type'] !== $account['auxiliary_type'] ||
                $line->entry_date > $data['matched_on']
            ) {
                AccountingBooks::fail('lines', 'LETTERING_SCOPE');
            }
            $accountId ??= $line->account_id;
            $party ??= $lineParty;
            if (
                $line->account_id !== $accountId ||
                $lineParty['id'] !== $party['id'] ||
                $lineParty['type'] !== $party['type']
            ) {
                AccountingBooks::fail('lines', 'LETTERING_SCOPE');
            }
            $amount = Money::parse($item['amount']);
            $allocated = $this->allocations()->where('a.ledger_line_id', $line->id)->first()?->allocated ?? '0';
            $face = BigInteger::of((string) $line->debit)->plus((string) $line->credit);
            if ($amount === 0 || BigInteger::of((string) $allocated)->plus($amount)->isGreaterThan($face)) {
                AccountingBooks::fail('amount', 'LETTERING_REMAINING');
            }
            $side = (string) $line->debit !== '0' ? 'debit' : 'credit';
            if ($side === 'debit') {
                $debit = $debit->plus($amount);
            } else {
                $credit = $credit->plus($amount);
            }
            $rows[] = [
                'ledger_line_id' => (int) $line->id,
                'entry_id' => $line->entry_id,
                'entry_sha256' => $line->entry_sha256,
                'number' => $line->number,
                'entry_date' => $line->entry_date,
                'position' => (int) $line->position,
                'account' => $account,
                'party' => $lineParty,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'side' => $side,
                'amount' => (string) $amount,
            ];
        }
        if (! $debit->isPositive() || ! $debit->isEqualTo($credit) || $debit->isGreaterThan(Money::MAX)) {
            AccountingBooks::fail('lines', 'LETTERING_TOTAL');
        }
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'account_id' => $accountId,
            'party' => $party,
            'matched_on' => $data['matched_on'],
            'reference' => $data['reference'],
            'note' => $data['note'],
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'lines' => $rows,
            'debit_total' => (string) $debit,
            'credit_total' => (string) $credit,
        ];
        $id = Records::id();
        DB::table('accounting_letterings')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'account_id' => $accountId,
            'party_type' => $party['type'],
            'party_key' => $party['id'],
            'matched_on' => $data['matched_on'],
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        foreach ($rows as $row) {
            DB::table('accounting_lettering_lines')->insert([
                'lettering_id' => $id,
                'ledger_line_id' => $row['ledger_line_id'],
                'side' => $row['side'],
                'amount' => $row['amount'],
            ]);
        }
        DB::table('accounting_letterings')
            ->where('id', $id)
            ->update(['status' => 'active']);
        Records::audit($actor, 'accounting.lettering_created', $id, [
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
        ]);

        return $this->dto(Records::row('accounting_letterings', $id, false));
    }

    public function void(object $book, string $id, array $data, User $actor): array
    {
        $group = app(AccountingBooks::class)->row('accounting_letterings', $id, $book);
        abort_unless((int) $group->version === $data['version'], 409);
        if ($group->status !== 'active' || ! hash_equals($group->sha256, $data['sha256'])) {
            AccountingBooks::fail('status', 'LETTERING_STATE');
        }
        $this->date($book, $data['voided_on']);
        if ($data['voided_on'] < $group->matched_on) {
            AccountingBooks::fail('date', 'LETTERING_CHRONOLOGY');
        }
        DB::table('accounting_letterings')
            ->where('id', $id)
            ->update([
                'status' => 'void',
                'version' => 2,
                'voided_on' => $data['voided_on'],
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_note' => $data['note'],
            ]);
        Records::audit($actor, 'accounting.lettering_voided', $id, [
            'sha256' => $group->sha256,
            'date' => $data['voided_on'],
            'note' => $data['note'],
        ]);

        return $this->dto(Records::row('accounting_letterings', $id, false));
    }

    public function report(object $book, array $data): array
    {
        app(AccountingBooks::class)->row('accounting_accounts', $data['account_id'], $book);
        $query = DB::table('accounting_entry_lines as l')
            ->join('accounting_entries as e', 'e.id', '=', 'l.entry_id')
            ->leftJoinSub($this->allocations($data['as_of']), 'a', 'a.ledger_line_id', '=', 'l.id')
            ->where('e.book_id', $book->id)
            ->where('e.status', 'posted')
            ->where('e.entry_date', '<=', $data['as_of'])
            ->where('l.account_id', $data['account_id'])
            ->whereIn('l.account_snapshot->auxiliary_type', ['customer', 'supplier'])
            ->whereNotNull('l.party')
            ->when(isset($data['party_id']), fn ($q) => $q->where('l.party->id', $data['party_id']))
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('l.position')
            ->select('l.*', 'e.number', 'e.entry_date', 'e.sha256 as entry_sha256', 'a.allocated');
        $totals = array_fill_keys(
            ['debit', 'credit', 'allocated_debit', 'allocated_credit', 'remaining_debit', 'remaining_credit'],
            BigInteger::zero(),
        );
        $counts = ['unmatched' => 0, 'partial' => 0, 'matched' => 0];
        $rows = [];
        $count = 0;
        $page = (int) ($data['page'] ?? 1);
        foreach ($query->cursor() as $line) {
            $side = (string) $line->debit !== '0' ? 'debit' : 'credit';
            $amount = BigInteger::of((string) $line->{$side});
            $allocated = BigInteger::of((string) ($line->allocated ?? '0'));
            $remaining = $amount->minus($allocated);
            $state = $remaining->isZero() ? 'matched' : ($allocated->isZero() ? 'unmatched' : 'partial');
            $counts[$state]++;
            $totals[$side] = $totals[$side]->plus($amount);
            $totals['allocated_'.$side] = $totals['allocated_'.$side]->plus($allocated);
            $totals['remaining_'.$side] = $totals['remaining_'.$side]->plus($remaining);
            if (($data['status'] ?? 'open') === 'open' && $state === 'matched') {
                continue;
            }
            if ($count >= ($page - 1) * 24 && $count < $page * 24) {
                $rows[] = [
                    'ledger_line_id' => (int) $line->id,
                    'entry_id' => $line->entry_id,
                    'entry_sha256' => $line->entry_sha256,
                    'number' => $line->number,
                    'entry_date' => $line->entry_date,
                    'position' => (int) $line->position,
                    'account' => json_decode($line->account_snapshot, true),
                    'party' => json_decode($line->party, true),
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                    'allocated' => (string) $allocated,
                    'remaining' => (string) $remaining,
                    'status' => $state,
                ];
            }
            $count++;
        }
        $totals['remaining_balance'] = $totals['remaining_debit']->minus($totals['remaining_credit']);

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'as_of' => $data['as_of'],
            'account_id' => $data['account_id'],
            'party_id' => $data['party_id'] ?? null,
            'status' => $data['status'] ?? 'open',
            'opening_scope' => 'all_posted_years',
            'totals_scope' => 'all_eligible_lines',
            'provisional' => true,
            'business_settlement_verified' => false,
            'rows' => $rows,
            'totals' => array_map(fn ($n) => (string) $n, $totals),
            'counts' => $counts,
            'meta' => ['page' => $page, 'last_page' => max(1, (int) ceil($count / 24)), 'total' => $count],
        ];
    }

    public function dto(object $group): array
    {
        return [
            'id' => $group->id,
            'book_id' => $group->book_id,
            'account_id' => $group->account_id,
            'party_type' => $group->party_type,
            'party_id' => $group->party_key,
            'matched_on' => $group->matched_on,
            'status' => $group->status,
            'version' => (int) $group->version,
            'snapshot' => json_decode($group->snapshot, true),
            'sha256' => $group->sha256,
            'created_by' => (int) $group->created_by,
            'created_at' => Records::iso($group->created_at),
            'voided_on' => $group->voided_on,
            'voided_by' => $group->voided_by === null ? null : (int) $group->voided_by,
            'voided_at' => Records::iso($group->voided_at),
            'void_note' => $group->void_note,
        ];
    }
}
