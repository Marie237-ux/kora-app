<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Explicit configuration only: no account numbers or availability assumptions are inferred. */
final class TreasuryBindings
{
    public function create(object $book, array $data, User $actor): array
    {
        $treasury = Records::row('treasury_accounts', $data['treasury_account_id']);
        $account = app(AccountingBooks::class)->row('accounting_accounts', $data['account_id'], $book);
        if (! $treasury->active || ! $account->active || ! $account->postable || $account->auxiliary_type !== 'none') {
            AccountingBooks::fail('account_id', 'TREASURY_CONFIGURATION');
        }
        $previous = DB::table('treasury_bindings')
            ->where('book_id', $book->id)
            ->where('treasury_account_id', $treasury->id)
            ->orderByDesc('version')
            ->first();
        abort_unless((int) ($previous?->version ?? 0) === $data['version'], 409);
        // A ledger account cannot silently represent two different treasury accounts in the same book.
        if (
            DB::table('treasury_bindings')
                ->where('book_id', $book->id)
                ->where('account_id', $account->id)
                ->where('treasury_account_id', '!=', $treasury->id)
                ->exists()
        ) {
            AccountingBooks::fail('account_id', 'TREASURY_ACCOUNT_SHARED');
        }
        if (
            $previous &&
            ($previous->account_id !== $account->id || $previous->inflow_side !== $data['inflow_side']) &&
            DB::table('treasury_statements')
                ->where('book_id', $book->id)
                ->where('treasury_account_id', $treasury->id)
                ->where('status', '!=', 'void')
                ->exists()
        ) {
            AccountingBooks::fail('account_id', 'TREASURY_BINDING_USED');
        }
        $id = Records::id();
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'treasury' => [
                'id' => $treasury->id,
                'code' => $treasury->code,
                'name' => $treasury->name,
                'kind' => $treasury->kind,
            ],
            'account' => app(AccountingBooks::class)->account($account),
            'inflow_side' => $data['inflow_side'],
            'chart_version' => (int) $book->chart_version,
            'version' => $data['version'] + 1,
            'note' => $data['note'],
        ];
        DB::table('treasury_bindings')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'treasury_account_id' => $treasury->id,
            'account_id' => $account->id,
            'inflow_side' => $data['inflow_side'],
            'chart_version' => $book->chart_version,
            'version' => $data['version'] + 1,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'treasury.binding_created', $id, ['note' => $data['note']]);

        return $this->dto(Records::row('treasury_bindings', $id, false));
    }

    public function review(object $book, string $id, array $data, User $actor): array
    {
        $binding = app(AccountingBooks::class)->row('treasury_bindings', $id, $book);
        if (
            $binding->reviewed_by ||
            ! hash_equals($binding->sha256, $data['sha256']) ||
            (int) $binding->chart_version !== (int) $book->chart_version
        ) {
            AccountingBooks::fail('sha256', 'TREASURY_REVIEW');
        }
        DB::table('treasury_bindings')
            ->where('id', $id)
            ->update(['reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        Records::audit($actor, 'treasury.binding_reviewed', $id, [
            'sha256' => $data['sha256'],
            'note' => $data['note'],
        ]);

        return $this->dto(Records::row('treasury_bindings', $id, false));
    }

    public function usable(object $book, string $id, bool $lockAccount = true): object
    {
        $binding = app(AccountingBooks::class)->row('treasury_bindings', $id, $book);
        $latest = DB::table('treasury_bindings')
            ->where('book_id', $book->id)
            ->where('treasury_account_id', $binding->treasury_account_id)
            ->max('version');
        $account = Records::row('accounting_accounts', $binding->account_id, false);
        if (
            (int) $latest !== (int) $binding->version ||
            (int) $binding->chart_version !== (int) $book->chart_version ||
            ! $account->active ||
            ! $account->postable ||
            ! Records::row('treasury_accounts', $binding->treasury_account_id, $lockAccount)->active ||
            ($book->mode === 'live' &&
                (! $binding->reviewed_by || (int) $book->reviewed_chart_version !== (int) $book->chart_version))
        ) {
            AccountingBooks::fail('binding_id', 'TREASURY_REVIEW');
        }

        return $binding;
    }

    public function dto(object $row): array
    {
        return [
            'id' => $row->id,
            'book_id' => $row->book_id,
            'treasury_account_id' => $row->treasury_account_id,
            'account_id' => $row->account_id,
            'inflow_side' => $row->inflow_side,
            'version' => (int) $row->version,
            'chart_version' => (int) $row->chart_version,
            'snapshot' => json_decode($row->snapshot, true),
            'sha256' => $row->sha256,
            'reviewed_by' => $row->reviewed_by === null ? null : (int) $row->reviewed_by,
            'reviewed_at' => $row->reviewed_at ? Records::iso($row->reviewed_at) : null,
        ];
    }
}
