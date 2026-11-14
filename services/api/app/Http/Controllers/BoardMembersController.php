<?php

namespace App\Http\Controllers;

use App\Domain\Boards\BoardInvitations;
use App\Domain\Boards\Boards;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BoardMembersController extends OperationalController
{
    private function owner(Request $http, string $id, int $version): object
    {
        $b = app(Boards::class)->access($id, $http->user(), true, true, true);
        app(Boards::class)->version($b, $version);

        return $b;
    }

    public function members(Request $http, string $id, Boards $boards)
    {
        $boards->access($id, $http->user(), false, true);

        return $this->page(
            DB::table('board_members')
                ->join('users', 'users.id', '=', 'board_members.user_id')
                ->where('board_id', $id)
                ->orderBy('board_members.user_id'),
            $http,
            fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'role' => $r->role,
                'revoked_at' => Records::iso($r->revoked_at),
            ],
        );
    }

    public function invitations(Request $http, string $id, Boards $boards)
    {
        $boards->access($id, $http->user(), false, true);

        return $this->page(
            DB::table('board_invitations')->where('board_id', $id)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($r) => [
                'id' => $r->id,
                'recipient_hint' => $r->recipient_hint,
                'role' => $r->role,
                'expires_at' => Records::iso($r->expires_at),
                'accepted_at' => Records::iso($r->accepted_at),
                'revoked_at' => Records::iso($r->revoked_at),
            ],
        );
    }

    public function invite(Request $http, string $id, BoardInvitations $invitations)
    {
        app(Boards::class)->access($id, $http->user(), false, true);
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'in:editor,viewer'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        [$local, $domain] = explode('@', $d['email'], 2);
        $d['recipient_hint'] = mb_substr($local, 0, 1).'…@'.mb_substr($domain, 0, 190);
        $d['email_hash'] = $invitations->emailHash($d['email']);
        unset($d['email']);

        return $this->command(
            $http,
            'board.invite:'.$id,
            $d,
            fn ($data) => $invitations->invite($this->owner($http, $id, $data['version']), $data, $http->user()),
            201,
        );
    }

    public function revokeInvite(Request $http, string $id, string $invitationId, BoardInvitations $invitations)
    {
        app(Boards::class)->access($id, $http->user(), false, true);
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'board.invite_revoke:'.$invitationId,
            $d,
            fn ($data) => $invitations->revoke($this->owner($http, $id, $data['version']), $invitationId, $http->user()),
        );
    }

    public function member(Request $http, string $id, int $userId, BoardInvitations $invitations)
    {
        app(Boards::class)->access($id, $http->user(), false, true);
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'role' => ['present', 'nullable', 'in:editor,viewer'],
        ]);

        return $this->command(
            $http,
            'board.member:'.$id.':'.$userId,
            $d,
            fn ($data) => $invitations->member(
                $this->owner($http, $id, $data['version']),
                $userId,
                $data['role'],
                $http->user(),
            ),
        );
    }

    public function preview(Request $http, string $token, BoardInvitations $invitations)
    {
        return ['data' => $invitations->preview($token, $http->user())];
    }

    public function accept(Request $http, string $token, BoardInvitations $invitations)
    {
        $invitations->authorizeAcceptance($token, $http->user());
        $d = $http->validate(['accepted' => ['required', 'accepted']]);

        return $this->command(
            $http,
            'board.invite_accept:'.hash('sha256', $token),
            $d,
            fn () => $invitations->accept($token, $http->user()),
        );
    }
}
