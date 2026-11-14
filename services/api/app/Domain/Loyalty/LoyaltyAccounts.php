<?php

namespace App\Domain\Loyalty;

use App\Domain\Procurement\Records;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class LoyaltyAccounts
{
    public function owned(User $actor, bool $lock = false): ?object
    {
        $q = DB::table('loyalty_accounts')->where('user_id', $actor->id);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public function enroll(User $actor): array
    {
        // The user row serialises first creation without a fictitious points balance.
        DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
        $account = $this->owned($actor, true);
        if (! $account) {
            $token = bin2hex(random_bytes(32));
            $id = Records::id();
            DB::table('loyalty_accounts')->insert([
                'id' => $id,
                'user_id' => $actor->id,
                'card_number' => 'KORA-'.strtoupper(bin2hex(random_bytes(12))),
                'qr_hash' => hash('sha256', $token),
                'qr_encrypted' => Crypt::encryptString($token),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Records::audit($actor, 'loyalty.card_created', $id);
            $account = Records::row('loyalty_accounts', $id, false);
        }

        return $this->dto($account);
    }

    public function rotate(object $account, int $version, User $actor): array
    {
        abort_unless((int) $account->version === $version, 409);
        $token = bin2hex(random_bytes(32));
        DB::table('loyalty_accounts')
            ->where('id', $account->id)
            ->update([
                'qr_hash' => hash('sha256', $token),
                'qr_encrypted' => Crypt::encryptString($token),
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'loyalty.qr_rotated', $account->id);

        return $this->dto(Records::row('loyalty_accounts', $account->id, false));
    }

    public function balance(string $id): int
    {
        return (int) DB::table('loyalty_movements')->where('account_id', $id)->sum('points_delta');
    }

    public function progression(object $account): array
    {
        $program = app(LoyaltyProgram::class)->current();
        if (! $program['rules']) {
            return ['eligible_spend' => '0', 'window_days' => null, 'tier' => null, 'next_tier' => null];
        }
        $rules = $program['rules'];
        $spend = BigInteger::zero();
        // A rolling history can exceed one order's XAF limit; never cast its aggregate through a float.
        foreach (
            DB::table('loyalty_grants')
                ->where('account_id', $account->id)
                ->whereNotNull('order_id')
                ->where('delivered_at', '>=', now()->subDays($rules['tier_window_days']))
                ->cursor(['eligible_amount', 'returned_amount']) as $grant
        ) {
            $spend = $spend->plus(BigInteger::of($grant->eligible_amount)->minus($grant->returned_amount));
        }
        $tier = null;
        $next = null;
        foreach ($rules['tiers'] as $candidate) {
            if ($spend->compareTo($candidate['threshold_xaf']) >= 0) {
                $tier = $candidate;
            } else {
                $next = $candidate;
                break;
            }
        }

        return [
            'eligible_spend' => (string) $spend,
            'window_days' => $rules['tier_window_days'],
            'tier' => $tier,
            'next_tier' => $next,
        ];
    }

    public function dto(object $account, bool $owner = true): array
    {
        $balance = $this->balance($account->id);
        $expiredHolds = DB::table('loyalty_redemption_allocations AS a')
            ->join('loyalty_redemptions AS r', 'r.id', '=', 'a.redemption_id')
            ->join('loyalty_grants AS g', 'g.id', '=', 'a.grant_id')
            ->where('r.account_id', $account->id)
            ->where('r.status', 'held')
            ->where('r.expires_at', '<=', now())
            ->where('a.status', 'held')
            ->where(fn ($q) => $q->whereNull('g.expires_at')->orWhere('g.expires_at', '>', now()))
            ->sum('a.points');
        $balance += (int) $expiredHolds;
        // Expired credits cannot remain spendable merely because the scheduler has not run yet.
        $expired = DB::table('loyalty_grants')
            ->where('account_id', $account->id)
            ->where('status', 'acquired')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id');
        foreach ($expired as $id) {
            $balance -= max(0, (int) DB::table('loyalty_movements')->where('grant_id', $id)->sum('points_delta'));
        }

        return [
            'id' => $account->id,
            'card_number' => $account->card_number,
            'version' => (int) $account->version,
            'status' => $account->status,
            'balance' => (string) $balance,
            'available_points' => (string) ($account->status === 'active' ? max(0, $balance) : 0),
            'pending_points' => (string) DB::table('loyalty_movements')
                ->where('account_id', $account->id)
                ->sum('pending_delta'),
            'reserved_points' => (string) DB::table('loyalty_redemptions')
                ->where('account_id', $account->id)
                ->where('status', 'held')
                ->where('expires_at', '>', now())
                ->sum('points'),
            'progression' => $this->progression($account),
            ...$owner ? ['qr_path' => '/api/v1/loyalty/cards/'.Crypt::decryptString($account->qr_encrypted)] : [],
        ];
    }

    public function movement(object $m): array
    {
        $text = json_decode($m->explanation, true);

        return [
            'id' => (int) $m->id,
            'kind' => $m->kind,
            'points_delta' => (string) $m->points_delta,
            'pending_delta' => (string) $m->pending_delta,
            'reserved_delta' => (string) $m->reserved_delta,
            'redemption_id' => $m->redemption_id,
            'grant_id' => $m->grant_id,
            'refund_id' => $m->refund_id,
            'explanation' => $text[app()->getLocale()] ?? $text['fr'],
            'created_at' => Records::iso($m->created_at),
        ];
    }
}
