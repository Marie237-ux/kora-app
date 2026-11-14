<?php

namespace App\Domain\Shared;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CommandBus
{
    public function execute(User $actor, string $command, ?string $key, array $input, callable $work): array
    {
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $hash = hash('sha256', $this->json($this->canonical($input)));

        return DB::transaction(function () use ($actor, $command, $key, $hash, $work, $input) {
            DB::table('command_receipts')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'actor_id' => $actor->id,
                'command' => $command,
                'request_key' => strtolower($key),
                'request_hash' => $hash,
                'input_snapshot' => $this->json($input),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receipt = DB::table('command_receipts')
                ->where('actor_id', $actor->id)
                ->where('command', $command)
                ->where('request_key', strtolower($key))
                ->lockForUpdate()
                ->first();
            abort_unless($receipt && hash_equals($receipt->request_hash, $hash), 409);
            if ($receipt->result !== null) {
                return json_decode($receipt->result, true);
            }
            $result = $work($receipt->id);
            DB::table('command_receipts')
                ->where('id', $receipt->id)
                ->update(['result' => $this->json($result), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    public function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function fingerprint(array $value): string
    {
        return hash('sha256', $this->json($this->canonical($value)));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
