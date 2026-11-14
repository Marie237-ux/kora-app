<?php

namespace App\Domain\Boards;

use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BoardInvitations
{
    public function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }

    public function invite(object $board, array $data, User $actor): array
    {
        if (hash_equals($this->emailHash($actor->email), $data['email_hash'])) {
            app(Boards::class)->fail('email', 'INVITE_SELF');
        }
        if (
            DB::table('board_invitations')
                ->where('board_id', $board->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count() >= 100
        ) {
            app(Boards::class)->fail('invitations', 'INVITATION_LIMIT');
        }
        DB::table('board_invitations')
            ->where('board_id', $board->id)
            ->where('email_hash', $data['email_hash'])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        $id = Records::id();
        $token = bin2hex(random_bytes(32));
        $expires = now()->addDays($data['expires_in_days']);
        DB::table('board_invitations')->insert([
            'id' => $id,
            'board_id' => $board->id,
            'email_hash' => $data['email_hash'],
            'recipient_hint' => $data['recipient_hint'],
            'token_hash' => hash('sha256', $token),
            'role' => $data['role'],
            'expires_at' => $expires,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $board = app(Boards::class)->change(
            $board,
            $actor,
            'invitation_created',
            [],
            ['invitation_id' => $id, 'role' => $data['role']],
        );

        return [
            'id' => $id,
            'board_version' => (int) $board->version,
            'url' => '/api/v1/board-invitations/'.$token,
            'role' => $data['role'],
            'expires_at' => $expires->toIso8601String(),
            'external_message_sent' => false,
        ];
    }

    public function forRecipient(string $token, User $actor): object
    {
        if (! $actor->hasVerifiedEmail()) {
            app(Boards::class)->fail('email', 'EMAIL_VERIFICATION_REQUIRED');
        }
        $r = DB::table('board_invitations')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($r && hash_equals($r->email_hash, $this->emailHash($actor->email)), 404);

        return $r;
    }

    public function preview(string $token, User $actor): array
    {
        $r = $this->forRecipient($token, $actor);
        $board = DB::table('boards')->where('id', $r->board_id)->whereNull('archived_at')->first();
        abort_unless($board, 404);

        return ['board_title' => $board->title, 'role' => $r->role, 'expires_at' => Records::iso($r->expires_at)];
    }

    public function authorizeAcceptance(string $token, User $actor): void
    {
        if (! $actor->hasVerifiedEmail()) {
            app(Boards::class)->fail('email', 'EMAIL_VERIFICATION_REQUIRED');
        }
        $r = DB::table('board_invitations')->where('token_hash', hash('sha256', $token))->first();
        abort_unless($r && hash_equals($r->email_hash, $this->emailHash($actor->email)), 404);
        if ($r->accepted_at) {
            abort_unless((int) $r->accepted_by === $actor->id, 404);
            app(Boards::class)->access($r->board_id, $actor);

            return;
        }
        $this->forRecipient($token, $actor);
    }

    public function accept(string $token, User $actor): array
    {
        $initial = $this->forRecipient($token, $actor);
        $board = DB::table('boards')
            ->where('id', $initial->board_id)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();
        abort_unless($board, 404);
        $r = $this->forRecipient($token, $actor);
        abort_if((int) $board->owner_id === $actor->id, 422);
        DB::table('board_members')->updateOrInsert(
            ['board_id' => $board->id, 'user_id' => $actor->id],
            ['role' => $r->role, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('board_invitations')
            ->where('id', $r->id)
            ->update(['accepted_at' => now(), 'accepted_by' => $actor->id, 'updated_at' => now()]);
        $board = app(Boards::class)->change(
            $board,
            $actor,
            'invitation_accepted',
            [],
            ['invitation_id' => $r->id, 'role' => $r->role],
        );

        return app(Boards::class)->dto($board, $actor);
    }

    public function revoke(object $board, string $id, User $actor): array
    {
        $r = DB::table('board_invitations')->where('board_id', $board->id)->where('id', $id)->first();
        abort_unless($r, 404);
        DB::table('board_invitations')
            ->where('id', $id)
            ->update(['revoked_at' => $r->revoked_at ?? now(), 'updated_at' => now()]);
        $board = app(Boards::class)->change($board, $actor, 'invitation_revoked', [], ['invitation_id' => $id]);

        return ['id' => $id, 'board_version' => (int) $board->version, 'revoked' => true];
    }

    public function member(object $board, int $userId, ?string $role, User $actor): array
    {
        abort_if($userId === (int) $board->owner_id, 422);
        $r = DB::table('board_members')
            ->where('board_id', $board->id)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->first();
        abort_unless($r, 404);
        DB::table('board_members')
            ->where('board_id', $board->id)
            ->where('user_id', $userId)
            ->update(['role' => $role ?? $r->role, 'revoked_at' => $role ? null : now(), 'updated_at' => now()]);
        // Pending older invitations cannot silently restore a revoked membership.
        if (! $role) {
            DB::table('board_invitations')
                ->where('board_id', $board->id)
                ->where('email_hash', $this->emailHash(User::findOrFail($userId)->email))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        }
        $board = app(Boards::class)->change(
            $board,
            $actor,
            $role ? 'member_role_changed' : 'member_revoked',
            [],
            ['user_id' => $userId, 'role' => $role],
        );

        return [
            'user_id' => $userId,
            'role' => $role,
            'revoked' => $role === null,
            'board_version' => (int) $board->version,
        ];
    }
}
