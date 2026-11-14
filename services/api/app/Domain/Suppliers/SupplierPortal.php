<?php

namespace App\Domain\Suppliers;

use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier portal (§19): settings, invitations and memberships. The portal is built and off by default; KORA
 * turns it on when it decides to, and publication stays with the team whatever happens. A supplier member only
 * ever reaches resources of its own supplier, checked on each resource.
 */
final class SupplierPortal
{
    public const ROLES = ['manager', 'staff'];

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('supplier_portal.'.$code)]);
    }

    public function settings(): array
    {
        $row = DB::table('business_settings')->where('key', 'supplier_portal')->first();
        $value = $row ? json_decode($row->value, true) : [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            // "review": the team checks each declared availability; "direct": a member's declaration applies at once.
            'availability' => $value['availability'] ?? 'review',
            'version' => $row ? (int) $row->version : 0,
        ];
    }

    public function saveSettings(array $data, User $actor): array
    {
        $row = DB::table('business_settings')->where('key', 'supplier_portal')->lockForUpdate()->first();
        abort_unless(($row ? (int) $row->version : 0) === $data['version'], 409);
        $value = Records::json(['enabled' => $data['enabled'], 'availability' => $data['availability']]);
        if ($row) {
            DB::table('business_settings')
                ->where('key', 'supplier_portal')
                ->update([
                    'value' => $value,
                    'version' => $row->version + 1,
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('business_settings')->insert([
                'key' => 'supplier_portal',
                'value' => $value,
                'version' => 1,
                'updated_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Records::audit($actor, 'supplier_portal.settings', 'supplier_portal', [
            'enabled' => $data['enabled'],
            'availability' => $data['availability'],
        ]);

        return $this->settings();
    }

    /** The active membership of this person for this supplier; the portal must be on. */
    public function member(User $user, string $supplierId): object
    {
        if (! $this->settings()['enabled']) {
            abort(
                response()->json(
                    [
                        'error' => [
                            'code' => 'SUPPLIER_PORTAL_DISABLED',
                            'message' => __('supplier_portal.DISABLED'),
                            'fields' => (object) [],
                        ],
                    ],
                    403,
                ),
            );
        }
        $member = DB::table('supplier_members')
            ->where('supplier_id', $supplierId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
        abort_unless($member, 404);

        return $member;
    }

    public function memberships(User $user): array
    {
        return [
            'enabled' => $this->settings()['enabled'],
            'suppliers' => DB::table('supplier_members AS m')
                ->join('suppliers AS s', 's.id', '=', 'm.supplier_id')
                ->where('m.user_id', $user->id)
                ->where('m.status', 'active')
                ->orderBy('s.name')
                ->get(['s.id', 's.name', 'm.role'])
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'role' => $r->role])
                ->all(),
        ];
    }

    public function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /**
     * The invitation link is returned to the team to pass on (no e-mail is sent while no channel is set up).
     * Only the person signed in with the invited address can accept it.
     */
    public function invite(string $supplierId, array $data, User $actor): array
    {
        Records::row('suppliers', $supplierId);
        $pending = DB::table('supplier_invitations')
            ->where('supplier_id', $supplierId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();
        if ($pending >= 20) {
            self::fail('email', 'INVITATION_LIMIT');
        }
        $token = bin2hex(random_bytes(32));
        $id = Records::id();
        DB::table('supplier_invitations')->insert([
            'id' => $id,
            'supplier_id' => $supplierId,
            'email_hash' => $this->emailHash($data['email']),
            'role' => $data['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'supplier_portal.invited', $id, ['supplier_id' => $supplierId, 'role' => $data['role']]);

        return [
            ...$this->invitationDto(Records::row('supplier_invitations', $id, false)),
            'url' => '/supplier-invitations/'.$token,
        ];
    }

    public function accept(string $token, User $user): array
    {
        $invitation = DB::table('supplier_invitations')
            ->where('token_hash', hash('sha256', $token))
            ->lockForUpdate()
            ->first();
        // The same answer for an unknown, used, revoked, expired or someone else's invitation.
        abort_unless(
            $invitation &&
                ! $invitation->accepted_at &&
                ! $invitation->revoked_at &&
                now()->lessThan($invitation->expires_at) &&
                hash_equals($invitation->email_hash, $this->emailHash((string) $user->email)),
            404,
        );
        if (! $user->hasVerifiedEmail()) {
            self::fail('email', 'EMAIL_NOT_VERIFIED');
        }
        $existing = DB::table('supplier_members')
            ->where('supplier_id', $invitation->supplier_id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            DB::table('supplier_members')
                ->where('id', $existing->id)
                ->update([
                    'role' => $invitation->role,
                    'status' => 'active',
                    'revoked_by' => null,
                    'revoked_at' => null,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('supplier_members')->insert([
                'id' => Records::id(),
                'supplier_id' => $invitation->supplier_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'invited_by' => $invitation->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('supplier_invitations')
            ->where('id', $invitation->id)
            ->update(['accepted_by' => $user->id, 'accepted_at' => now(), 'updated_at' => now()]);
        Records::audit($user, 'supplier_portal.joined', $invitation->id, ['supplier_id' => $invitation->supplier_id]);

        return $this->memberships($user);
    }

    public function revokeInvitation(string $id, User $actor): array
    {
        $invitation = Records::row('supplier_invitations', $id);
        if ($invitation->accepted_at || $invitation->revoked_at) {
            self::fail('invitation', 'INVITATION_STATE');
        }
        DB::table('supplier_invitations')
            ->where('id', $id)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        Records::audit($actor, 'supplier_portal.invitation_revoked', $id);

        return $this->invitationDto(Records::row('supplier_invitations', $id, false));
    }

    /** Takes effect on the next request of that person. */
    public function revokeMember(string $id, User $actor): array
    {
        $member = Records::row('supplier_members', $id);
        if ($member->status !== 'active') {
            self::fail('member', 'MEMBER_STATE');
        }
        DB::table('supplier_members')
            ->where('id', $id)
            ->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
        Records::audit($actor, 'supplier_portal.member_revoked', $id, ['supplier_id' => $member->supplier_id]);

        return $this->memberDto(Records::row('supplier_members', $id, false));
    }

    public function access(string $supplierId): array
    {
        Records::row('suppliers', $supplierId, false);

        return [
            'members' => DB::table('supplier_members')
                ->where('supplier_id', $supplierId)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($m) => $this->memberDto($m))
                ->all(),
            'invitations' => DB::table('supplier_invitations')
                ->where('supplier_id', $supplierId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($i) => $this->invitationDto($i))
                ->all(),
        ];
    }

    public function memberDto(object $m): array
    {
        $user = DB::table('users')
            ->where('id', $m->user_id)
            ->first(['name']);

        return [
            'id' => $m->id,
            'supplier_id' => $m->supplier_id,
            'user_id' => (int) $m->user_id,
            'name' => $user?->name,
            'role' => $m->role,
            'status' => $m->status,
            'revoked_at' => Records::iso($m->revoked_at),
            'created_at' => Records::iso($m->created_at),
        ];
    }

    public function invitationDto(object $i): array
    {
        return [
            'id' => $i->id,
            'supplier_id' => $i->supplier_id,
            'role' => $i->role,
            'status' => $i->accepted_at
                ? 'accepted'
                : ($i->revoked_at
                    ? 'revoked'
                    : (now()->greaterThanOrEqualTo($i->expires_at)
                        ? 'expired'
                        : 'pending')),
            'expires_at' => Records::iso($i->expires_at),
            'accepted_at' => Records::iso($i->accepted_at),
            'created_at' => Records::iso($i->created_at),
        ];
    }
}
