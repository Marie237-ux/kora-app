<?php

namespace App\Domain\Boards;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BoardSharing
{
    public function visibility(object $board, string $visibility, User $actor): array
    {
        $board = app(Boards::class)->change(
            $board,
            $actor,
            'visibility_changed',
            ['visibility' => $visibility, 'share_token_hash' => null, 'share_expires_at' => null],
            ['visibility' => $visibility],
        );

        return app(Boards::class)->dto($board, $actor);
    }

    public function share(object $board, int $days, User $actor): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = now()->addDays($days);
        $board = app(Boards::class)->change(
            $board,
            $actor,
            'unlisted_link_created',
            ['visibility' => 'unlisted', 'share_token_hash' => hash('sha256', $token), 'share_expires_at' => $expires],
            ['expires_at' => $expires->toIso8601String()],
        );

        return [
            'board' => app(Boards::class)->dto($board, $actor),
            'url' => '/api/v1/boards/shared/'.$board->id.'/'.$token,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public function archive(object $board, User $actor): array
    {
        app(Boards::class)->change($board, $actor, 'archived', [
            'archived_at' => now(),
            'visibility' => 'private',
            'share_token_hash' => null,
            'share_expires_at' => null,
        ]);
        DB::table('board_invitations')
            ->where('board_id', $board->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return ['id' => $board->id, 'archived' => true];
    }
}
