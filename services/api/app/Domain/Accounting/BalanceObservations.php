<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reviewed external evidence at an explicit instant; never a live balance query or journal posting. */
final class BalanceObservations
{
    private function latest(string $book, string $account): ?object
    {
        return DB::table('treasury_balance_observations')
            ->where('book_id', $book)
            ->where('treasury_account_id', $account)
            ->orderByDesc('sequence')
            ->first();
    }

    private function evidence(string $id): object
    {
        return app(PrivateDocuments::class)->requireClean($id, null, ['accounting_evidence']);
    }

    public function create(object $book, array $data, User $actor): array
    {
        $binding = app(TreasuryBindings::class)->usable($book, $data['binding_id']);
        $account = Records::row('treasury_accounts', $binding->treasury_account_id, false);
        $previous = $this->latest($book->id, $account->id);
        abort_unless((int) ($previous?->sequence ?? 0) === $data['sequence'], 409);
        $observed = CarbonImmutable::parse($data['observed_at'])->utc()->startOfSecond();
        $expires = CarbonImmutable::parse($data['expires_at'])->utc()->startOfSecond();
        if (
            $observed->isAfter(CarbonImmutable::now('UTC')) ||
            ! $expires->isAfter($observed) ||
            $expires->isAfter($observed->addDay()) ||
            ($previous && $observed->isBefore(CarbonImmutable::parse($previous->observed_at, 'UTC')))
        ) {
            AccountingBooks::fail('observed_at', 'BALANCE_OBSERVATION_DATE');
        }
        $negative = str_starts_with($data['reported_balance'], '-');
        $absolute = Money::parse($negative ? substr($data['reported_balance'], 1) : $data['reported_balance']);
        if ($negative && $absolute === 0) {
            AccountingBooks::fail('reported_balance', 'BALANCE_OBSERVATION_AMOUNT');
        }
        $balance = $negative ? -$absolute : $absolute;
        $provider = $account->kind === 'monetbil';
        $available = isset($data['available_funds']) ? Money::parse($data['available_funds']) : null;
        $withdrawable = isset($data['withdrawable_funds']) ? Money::parse($data['withdrawable_funds']) : null;
        if (
            ($provider &&
                ($balance < 0 || $available !== null || $withdrawable === null || $withdrawable > $balance)) ||
            (! $provider && ($withdrawable !== null || $available === null || $available > max(0, $balance)))
        ) {
            AccountingBooks::fail('amount', 'BALANCE_OBSERVATION_AMOUNT');
        }
        $document = $this->evidence($data['document_id']);
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'binding' => app(TreasuryBindings::class)->dto($binding),
            'reference' => trim($data['reference']),
            'reported_balance' => (string) $balance,
            'available_funds' => $available === null ? null : (string) $available,
            'withdrawable_funds' => $withdrawable === null ? null : (string) $withdrawable,
            'observed_at' => $observed->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'note' => $data['note'],
            'verification_method' => 'manual_evidence_review',
        ];
        $id = Records::id();
        DB::table('treasury_balance_observations')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'treasury_account_id' => $account->id,
            'binding_id' => $binding->id,
            'sequence' => $data['sequence'] + 1,
            'reference' => $snapshot['reference'],
            'reported_balance' => $balance,
            'available_funds' => $available,
            'withdrawable_funds' => $withdrawable,
            'observed_at' => $observed,
            'expires_at' => $expires,
            'document_id' => $document->id,
            'document_sha256' => $document->sha256,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'treasury.balance_observed', $id, ['sequence' => $data['sequence'] + 1]);

        return $this->dto(Records::row('treasury_balance_observations', $id, false));
    }

    public function transition(object $book, string $id, string $action, array $data, User $actor): array
    {
        $r = app(AccountingBooks::class)->row('treasury_balance_observations', $id, $book);
        abort_unless((int) $r->version === $data['version'], 409);
        if (
            ! hash_equals($r->sha256, $data['sha256']) ||
            ! in_array($r->status, ['pending', 'approved'], true) ||
            ($book->mode === 'live' && (int) $r->created_by === $actor->id)
        ) {
            AccountingBooks::fail('status', 'BALANCE_OBSERVATION_STATE');
        }
        if ($action === 'approve') {
            if (
                $r->status !== 'pending' ||
                $this->latest($book->id, $r->treasury_account_id)->id !== $id ||
                CarbonImmutable::now('UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($r->expires_at, 'UTC'))
            ) {
                AccountingBooks::fail('status', 'BALANCE_OBSERVATION_STATE');
            }
            app(TreasuryBindings::class)->usable($book, $r->binding_id);
            $document = $this->evidence($r->document_id);
            if (! hash_equals($r->document_sha256, $document->sha256)) {
                AccountingBooks::fail('document_id', 'SOURCE_CHANGED');
            }
            $changes = [
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'],
            ];
        } else {
            $document = $this->evidence($data['document_id']);
            $changes = [
                'status' => 'void',
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_note' => $data['note'],
                'void_document_id' => $document->id,
                'void_document_sha256' => $document->sha256,
            ];
        }
        DB::table('treasury_balance_observations')
            ->where('id', $id)
            ->update([...$changes, 'version' => $r->version + 1]);
        Records::audit($actor, 'treasury.balance_'.$action, $id, ['note' => $data['note']]);

        return $this->dto(Records::row('treasury_balance_observations', $id, false));
    }

    private function state(object $book, ?object $r): string
    {
        if (! $r) {
            return 'missing';
        }
        if ($r->status === 'void') {
            return 'void';
        }
        if ($r->status === 'pending') {
            return 'pending_review';
        }
        if (CarbonImmutable::now('UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($r->expires_at, 'UTC'))) {
            return 'expired';
        }
        try {
            app(TreasuryBindings::class)->usable($book, $r->binding_id, false);
        } catch (ValidationException) {
            return 'configuration_changed';
        }
        $d = Records::row('private_documents', $r->document_id, false);
        if (
            $d->scan_status !== 'clean' ||
            $d->purpose !== 'accounting_evidence' ||
            $d->supplier_id !== null ||
            ! hash_equals($r->document_sha256, $d->sha256)
        ) {
            return 'evidence_unavailable';
        }

        return 'reviewed_fresh';
    }

    public function summary(object $book): array
    {
        $query = DB::table('treasury_accounts')->where('active', true)->orderBy('code')->orderBy('id');
        if ($book->mode !== 'live') {
            $query->whereIn(
                'id',
                DB::table('treasury_bindings')->where('book_id', $book->id)->select('treasury_account_id'),
            );
        }
        $accounts = [];
        $instants = [];
        $complete = true;
        $totals = array_fill_keys(['available_funds', 'provider_held_funds', 'withdrawable_funds'], BigInteger::zero());
        foreach ($query->get() as $a) {
            $r = $this->latest($book->id, $a->id);
            $state = $this->state($book, $r);
            $dto = $r ? $this->dto($r) : null;
            $accounts[] = [
                'treasury_account_id' => $a->id,
                'code' => $a->code,
                'kind' => $a->kind,
                'state' => $state,
                'observation' => $dto,
            ];
            if ($state !== 'reviewed_fresh') {
                $complete = false;

                continue;
            }
            $instants[$dto['snapshot']['observed_at']] = true;
            if ($a->kind === 'monetbil') {
                $totals['provider_held_funds'] = $totals['provider_held_funds']->plus((string) $r->reported_balance);
                $totals['withdrawable_funds'] = $totals['withdrawable_funds']->plus((string) $r->withdrawable_funds);
            } else {
                $totals['available_funds'] = $totals['available_funds']->plus((string) $r->available_funds);
            }
        }
        $complete = $complete && count($accounts) > 0;
        $coherent = $complete && count($instants) === 1;

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'scope' => $book->mode === 'live' ? 'active_treasury_accounts' : 'active_bound_accounts',
            'verification_method' => 'manual_evidence_review',
            'as_of' => $coherent ? array_key_first($instants) : null,
            'all_accounts_reviewed' => $complete,
            'same_instant' => $coherent,
            'accounts' => $accounts,
            'totals' => array_map(fn ($v) => $coherent ? (string) $v : null, $totals),
            'current_availability_verified' => false,
            'provisional' => true,
        ];
    }

    public function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'book_id' => $r->book_id,
            'treasury_account_id' => $r->treasury_account_id,
            'sequence' => (int) $r->sequence,
            'status' => $r->status,
            'version' => (int) $r->version,
            'snapshot' => json_decode($r->snapshot, true),
            'sha256' => $r->sha256,
            'created_by' => (int) $r->created_by,
            'reviewed_by' => $r->reviewed_by === null ? null : (int) $r->reviewed_by,
            'reviewed_at' => $r->reviewed_at ? Records::iso($r->reviewed_at) : null,
            'review_note' => $r->review_note,
            'voided_by' => $r->voided_by === null ? null : (int) $r->voided_by,
            'voided_at' => $r->voided_at ? Records::iso($r->voided_at) : null,
            'void_note' => $r->void_note,
            'void_document_id' => $r->void_document_id,
            'void_document_sha256' => $r->void_document_sha256,
        ];
    }
}
