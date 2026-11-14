<?php

namespace App\Domain\Referrals;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-level referral (§24). A customer with no paid order joins with a sponsor's code; the sponsor earns a share
 * of the referee's first qualifying purchases, once paid and delivered and after a validation delay, recomputed
 * net of refunds at that moment and within the program budget. Nothing is earned for the sign-up alone. Rewards
 * are cash claims paid out on request after a team check, never loyalty points.
 */
final class Referrals
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('referrals.'.$code)]);
    }

    public function createProgram(array $data, User $actor): array
    {
        // PostgreSQL refuses FOR UPDATE with an aggregate: lock the rows, then count. Two simultaneous creations
        // that still collide hit the unique version and are answered as a conflict.
        $version = (int) DB::table('referral_programs')->lockForUpdate()->pluck('version')->max() + 1;
        $id = Records::id();
        DB::table('referral_programs')->insert([
            'id' => $id,
            'version' => $version,
            'rate_bp' => $data['rate_bp'],
            'purchase_cap' => $data['purchase_cap'],
            'purchases' => $data['purchases'],
            'payout_threshold' => $data['payout_threshold'],
            'validation_days' => $data['validation_days'],
            'budget' => $data['budget'],
            'terms' => Records::json($data['terms']),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'referral.program_created', $id, ['version' => $version]);

        return $this->programDto(Records::row('referral_programs', $id, false));
    }

    /** Only one program is active; the previous one is retired and keeps applying to the referrals it signed. */
    public function activate(string $id, User $actor): array
    {
        $program = Records::row('referral_programs', $id);
        if ($program->status !== 'draft') {
            self::fail('program', 'PROGRAM_STATE');
        }
        DB::table('referral_programs')
            ->where('status', 'active')
            ->update(['status' => 'retired', 'retired_at' => now(), 'updated_at' => now()]);
        DB::table('referral_programs')
            ->where('id', $id)
            ->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
        Records::audit($actor, 'referral.program_activated', $id, ['version' => (int) $program->version]);

        return $this->programDto(Records::row('referral_programs', $id, false));
    }

    public function active(): ?object
    {
        return DB::table('referral_programs')->where('status', 'active')->first();
    }

    /** The customer's own sponsor code, created on first request. Opaque: no name, phone or number in it. */
    public function code(User $user): string
    {
        $existing = DB::table('referral_codes')->where('user_id', $user->id)->value('code');
        if ($existing) {
            return $existing;
        }
        do {
            $code = 'K'.strtoupper(Str::random(7));
        } while (DB::table('referral_codes')->where('code', $code)->exists());
        DB::table('referral_codes')->insertOrIgnore(['user_id' => $user->id, 'code' => $code, 'created_at' => now()]);

        return DB::table('referral_codes')->where('user_id', $user->id)->value('code');
    }

    /**
     * A new customer joins a sponsor. Self-referral and a second sponsor are refused; a shared phone or address
     * only flags the referral for a human review, it never proves fraud on its own.
     */
    public function join(User $referee, string $code): array
    {
        $program = $this->active();
        if (! $program) {
            self::fail('code', 'PROGRAM_INACTIVE');
        }
        $referrer = DB::table('referral_codes')->where('code', strtoupper(trim($code)))->value('user_id');
        if (! $referrer) {
            self::fail('code', 'CODE_UNKNOWN');
        }
        if ((int) $referrer === $referee->id) {
            self::fail('code', 'SELF_REFERRAL');
        }
        DB::table('users')->where('id', $referee->id)->lockForUpdate()->first();
        if (DB::table('referrals')->where('referee_id', $referee->id)->exists()) {
            self::fail('code', 'ALREADY_REFERRED');
        }
        if (DB::table('orders')->where('user_id', $referee->id)->whereNotNull('paid_at')->exists()) {
            self::fail('code', 'NOT_NEW_CUSTOMER');
        }
        $signals = $this->signals((int) $referrer, $referee->id);
        $id = Records::id();
        DB::table('referrals')->insert([
            'id' => $id,
            'referrer_id' => $referrer,
            'referee_id' => $referee->id,
            'program_id' => $program->id,
            'signals' => Records::json($signals),
            'status' => $signals ? 'flagged' : 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($referee, 'referral.joined', $id, ['referrer_id' => (int) $referrer, 'signals' => $signals]);

        return ['joined' => true, 'program_version' => (int) $program->version];
    }

    /** Overlaps worth a look by the team: shared contact phone or same district and landmark. */
    private function signals(int $referrerId, int $refereeId): array
    {
        $a = DB::table('customer_addresses')->where('user_id', $referrerId)->get();
        $b = DB::table('customer_addresses')->where('user_id', $refereeId)->get();
        $signals = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x->contact_phone && $x->contact_phone === $y->contact_phone) {
                    $signals['shared_phone'] = true;
                }
                if (
                    mb_strtolower(trim($x->district.'|'.$x->landmark)) ===
                    mb_strtolower(trim($y->district.'|'.$y->landmark))
                ) {
                    $signals['shared_address'] = true;
                }
            }
        }

        return array_keys($signals);
    }

    public function review(string $id, array $data, User $actor): array
    {
        $referral = Records::row('referrals', $id);
        abort_unless((int) $referral->version === $data['version'], 409);
        if ($referral->status === 'blocked') {
            self::fail('referral', 'REFERRAL_STATE');
        }
        DB::table('referrals')
            ->where('id', $id)
            ->update([
                'status' => $data['decision'] === 'approve' ? 'active' : 'blocked',
                'review_note' => $data['note'],
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'version' => $referral->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'referral.reviewed', $id, ['decision' => $data['decision']]);

        return $this->referralDto(Records::row('referrals', $id, false));
    }

    /** Items paid net of the loyalty discount, minus refunds actually paid; delivery never counts. */
    public function base(object $order): int
    {
        $refunded = (int) DB::table('customer_refunds')
            ->where('order_id', $order->id)
            ->where('status', 'paid')
            ->sum('amount');

        return max(0, (int) $order->items_total - (int) $order->loyalty_discount - $refunded);
    }

    /**
     * Scheduled: reward the referees' paid and delivered purchases, then settle rewards whose delay is over.
     * Locks: referral, then program (budget), so two settlements cannot overspend it.
     */
    public function reconcile(int $limit): array
    {
        $created = 0;
        $settled = 0;
        $orders = DB::table('orders AS o')
            ->join('referrals AS r', 'r.referee_id', '=', 'o.user_id')
            ->leftJoin('referral_rewards AS w', 'w.order_id', '=', 'o.id')
            ->whereNull('w.id')
            ->whereIn('r.status', ['active', 'flagged'])
            ->where('o.status', 'paid')
            ->whereNotNull('o.delivered_at')
            ->whereColumn('o.paid_at', '>=', 'r.created_at')
            ->orderBy('o.paid_at')
            ->orderBy('o.id')
            ->limit($limit)
            ->get(['o.id AS order_id', 'r.id AS referral_id']);
        foreach ($orders as $row) {
            $created += (int) DB::transaction(function () use ($row) {
                $referral = Records::row('referrals', $row->referral_id);
                $program = Records::row('referral_programs', $referral->program_id, false);
                $counted = DB::table('referral_rewards')
                    ->where('referral_id', $referral->id)
                    ->where('status', '!=', 'cancelled')
                    ->count();
                if (
                    $counted >= $program->purchases ||
                    DB::table('referral_rewards')->where('order_id', $row->order_id)->exists()
                ) {
                    return false;
                }
                $order = DB::table('orders')->where('id', $row->order_id)->first();
                $base = $this->base($order);
                DB::table('referral_rewards')->insert([
                    'id' => Records::id(),
                    'referral_id' => $referral->id,
                    'order_id' => $order->id,
                    'sequence' => (int) DB::table('referral_rewards')->where('referral_id', $referral->id)->max('sequence') + 1,
                    'base_amount' => $base,
                    'amount' => min(intdiv($base * (int) $program->rate_bp, 10000), (int) $program->purchase_cap),
                    'available_at' => CarbonImmutable::parse($order->delivered_at, 'UTC')->addDays(
                        (int) $program->validation_days,
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            }, 3);
        }
        $due = DB::table('referral_rewards AS w')
            ->join('referrals AS r', 'r.id', '=', 'w.referral_id')
            ->where('w.status', 'pending')
            ->where('w.available_at', '<=', now())
            ->where('r.status', '!=', 'flagged')
            ->orderBy('w.available_at')
            ->orderBy('w.id')
            ->limit($limit)
            ->pluck('w.id');
        foreach ($due as $id) {
            DB::transaction(function () use ($id) {
                $initial = Records::row('referral_rewards', $id, false);
                $referral = Records::row('referrals', $initial->referral_id);
                $program = Records::row('referral_programs', $referral->program_id);
                $reward = Records::row('referral_rewards', $id);
                if ($reward->status !== 'pending') {
                    return;
                }
                $order = DB::table('orders')->where('id', $reward->order_id)->first();
                $base = $this->base($order);
                $amount = min(intdiv($base * (int) $program->rate_bp, 10000), (int) $program->purchase_cap);
                $spent = (int) DB::table('referral_rewards AS w')
                    ->join('referrals AS r', 'r.id', '=', 'w.referral_id')
                    ->where('r.program_id', $program->id)
                    ->where('w.status', 'validated')
                    ->sum('w.amount');
                $amount = min($amount, max(0, (int) $program->budget - $spent));
                [$status, $reason] = match (true) {
                    $referral->status === 'blocked' => ['cancelled', 'referral_blocked'],
                    $base === 0 || $order->status !== 'paid' => ['cancelled', 'returned'],
                    $amount === 0 => ['exhausted', 'budget_exhausted'],
                    default => ['validated', null],
                };
                DB::table('referral_rewards')
                    ->where('id', $id)
                    ->update([
                        'status' => $status,
                        'base_amount' => min($base, (int) $reward->base_amount),
                        'amount' => $status === 'validated' ? min($amount, min($base, (int) $reward->base_amount)) : 0,
                        'reason' => $reason,
                        'settled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }, 3);
            $settled++;
        }

        return ['created' => $created, 'settled' => $settled];
    }

    public function balance(int $userId): int
    {
        $earned = (int) DB::table('referral_rewards AS w')
            ->join('referrals AS r', 'r.id', '=', 'w.referral_id')
            ->where('r.referrer_id', $userId)
            ->where('w.status', 'validated')
            ->sum('w.amount');
        $claimed = (int) DB::table('referral_payouts')
            ->where('user_id', $userId)
            ->whereIn('status', ['requested', 'approved', 'paid'])
            ->sum('amount');

        return $earned - $claimed;
    }

    public function requestPayout(User $user, array $data): array
    {
        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
        $threshold =
            (int) ($this->active()?->payout_threshold ??
                DB::table('referral_programs')->orderByDesc('version')->value('payout_threshold'));
        if (! $threshold) {
            self::fail('amount', 'PROGRAM_INACTIVE');
        }
        if ($data['amount'] < $threshold || $data['amount'] > $this->balance($user->id)) {
            self::fail('amount', 'PAYOUT_AMOUNT');
        }
        $id = Records::id();
        DB::table('referral_payouts')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'amount' => $data['amount'],
            'channel' => $data['channel'],
            'destination' => $data['destination'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($user, 'referral.payout_requested', $id, ['amount' => $data['amount']]);

        return $this->payoutDto(Records::row('referral_payouts', $id, false), true);
    }

    public function reviewPayout(string $id, array $data, User $actor, bool $approve): array
    {
        $payout = Records::row('referral_payouts', $id);
        abort_unless((int) $payout->version === $data['version'], 409);
        if ($payout->status !== 'requested') {
            self::fail('payout', 'PAYOUT_STATE');
        }
        DB::table('referral_payouts')
            ->where('id', $id)
            ->update([
                'status' => $approve ? 'approved' : 'rejected',
                'review_note' => $data['note'],
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'version' => $payout->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'referral.payout_'.($approve ? 'approved' : 'rejected'), $id, []);

        return $this->payoutDto(Records::row('referral_payouts', $id, false), false);
    }

    /** Recorded once the transfer really happened, with its proof and the provider's reference. */
    public function paid(string $id, array $data, User $actor): array
    {
        $payout = Records::row('referral_payouts', $id);
        abort_unless((int) $payout->version === $data['version'], 409);
        if ($payout->status !== 'approved') {
            self::fail('payout', 'PAYOUT_STATE');
        }
        app(PrivateDocuments::class)->requireClean($data['evidence_id'], null, ['customer_payment_evidence']);
        DB::table('referral_payouts')
            ->where('id', $id)
            ->update([
                'status' => 'paid',
                'evidence_id' => $data['evidence_id'],
                'reference' => $data['reference'],
                'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc(),
                'paid_by' => $actor->id,
                'version' => $payout->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'referral.payout_paid', $id, ['reference' => $data['reference']]);

        return $this->payoutDto(Records::row('referral_payouts', $id, false), false);
    }

    /** Sponsor's own view: code, terms, referees without their identity, rewards, balance and payouts. */
    public function mine(User $user): array
    {
        $program = $this->active();
        $locale = app()->getLocale();
        $referrals = DB::table('referrals')
            ->where('referrer_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'code' => $this->code($user),
            'program' => $program
                ? [
                    'version' => (int) $program->version,
                    'rate_bp' => (int) $program->rate_bp,
                    'purchase_cap' => (string) $program->purchase_cap,
                    'purchases' => (int) $program->purchases,
                    'payout_threshold' => (string) $program->payout_threshold,
                    'validation_days' => (int) $program->validation_days,
                    'terms' => json_decode($program->terms, true)[$locale] ?? null,
                ]
                : null,
            'sponsored_by_someone' => DB::table('referrals')->where('referee_id', $user->id)->exists(),
            // Referees stay anonymous to their sponsor: an order number of theirs is never shown either.
            'referees' => $referrals
                ->values()
                ->map(
                    fn ($r, $i) => [
                        'label' => '#'.($i + 1),
                        'joined_at' => Records::iso($r->created_at),
                        'rewards' => DB::table('referral_rewards')
                            ->where('referral_id', $r->id)
                            ->orderBy('sequence')
                            ->get()
                            ->map(
                                fn ($w) => [
                                    'sequence' => (int) $w->sequence,
                                    'status' => $w->status,
                                    'amount' => (string) $w->amount,
                                    'available_at' => Records::iso($w->available_at),
                                ],
                            )
                            ->values(),
                    ],
                )
                ->all(),
            'balance' => (string) $this->balance($user->id),
            'payouts' => DB::table('referral_payouts')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($p) => $this->payoutDto($p, true))
                ->values(),
        ];
    }

    public function programDto(object $p): array
    {
        return [
            'id' => $p->id,
            'version' => (int) $p->version,
            'status' => $p->status,
            'rate_bp' => (int) $p->rate_bp,
            'purchase_cap' => (string) $p->purchase_cap,
            'purchases' => (int) $p->purchases,
            'payout_threshold' => (string) $p->payout_threshold,
            'validation_days' => (int) $p->validation_days,
            'budget' => (string) $p->budget,
            'budget_used' => (string) DB::table('referral_rewards AS w')
                ->join('referrals AS r', 'r.id', '=', 'w.referral_id')
                ->where('r.program_id', $p->id)
                ->where('w.status', 'validated')
                ->sum('w.amount'),
            'terms' => json_decode($p->terms, true),
            'activated_at' => Records::iso($p->activated_at),
            'retired_at' => Records::iso($p->retired_at),
        ];
    }

    public function referralDto(object $r): array
    {
        return [
            'id' => $r->id,
            'referrer_id' => (int) $r->referrer_id,
            'referee_id' => (int) $r->referee_id,
            'program_version' => (int) DB::table('referral_programs')->where('id', $r->program_id)->value('version'),
            'signals' => json_decode($r->signals, true),
            'status' => $r->status,
            'review_note' => $r->review_note,
            'version' => (int) $r->version,
            'rewards' => DB::table('referral_rewards')
                ->where('referral_id', $r->id)
                ->orderBy('sequence')
                ->get()
                ->map(
                    fn ($w) => [
                        'sequence' => (int) $w->sequence,
                        'order_id' => $w->order_id,
                        'base_amount' => (string) $w->base_amount,
                        'amount' => (string) $w->amount,
                        'status' => $w->status,
                        'reason' => $w->reason,
                        'available_at' => Records::iso($w->available_at),
                    ],
                )
                ->values(),
            'created_at' => Records::iso($r->created_at),
        ];
    }

    public function payoutDto(object $p, bool $own): array
    {
        return [
            'id' => $p->id,
            ...$own
                ? []
                : ['user_id' => (int) $p->user_id, 'review_note' => $p->review_note, 'evidence_id' => $p->evidence_id],
            'amount' => (string) $p->amount,
            'channel' => $p->channel,
            'destination' => $p->destination,
            'status' => $p->status,
            'reference' => $p->reference,
            'version' => (int) $p->version,
            'paid_at' => Records::iso($p->paid_at),
            'created_at' => Records::iso($p->created_at),
        ];
    }
}
