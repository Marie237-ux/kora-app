<?php

namespace App\Domain\Shared;

use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SessionCommands
{
    public function execute(Request $request, string $command, array $input, callable $work): array
    {
        $key = $request->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $baseOwner = app(SessionIdentity::class)->hash($request);
        $owner = hash('sha256', $baseOwner.':'.($request->user()?->id ?? 'guest'));
        $bus = app(CommandBus::class);
        $hash = $bus->fingerprint($input);

        return DB::transaction(function () use ($owner, $command, $key, $hash, $work, $bus) {
            DB::table('session_command_receipts')->insertOrIgnore([
                'id' => Records::id(),
                'owner_hash' => $owner,
                'command' => $command,
                'request_key' => strtolower($key),
                'request_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
                'expires_at' => now()->addDays(max(1, min(90, (int) config('kora.session_command_days', 7)))),
            ]);
            $r = DB::table('session_command_receipts')
                ->where('owner_hash', $owner)
                ->where('command', $command)
                ->where('request_key', strtolower($key))
                ->lockForUpdate()
                ->first();
            abort_unless($r && hash_equals($hash, $r->request_hash), 409);
            if ($r->expires_at && now()->greaterThanOrEqualTo($r->expires_at)) {
                Money::fail('command', 'SESSION_COMMAND_EXPIRED');
            }
            if ($r->result !== null) {
                return json_decode($r->result, true);
            }
            $result = $work();
            DB::table('session_command_receipts')
                ->where('id', $r->id)
                ->update(['result' => $bus->json($result), 'updated_at' => now()]);

            return $result;
        }, 3);
    }
}
