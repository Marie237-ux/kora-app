<?php

namespace App\Domain\Shared;

use Illuminate\Http\Request;

final class SessionIdentity
{
    public function hash(Request $request, bool $create = true): ?string
    {
        abort_unless($request->hasSession(), 419);
        $token = $request->session()->get('kora_anonymous_owner');
        if (! is_string($token) || strlen($token) !== 64) {
            if (! $create) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $request->session()->put('kora_anonymous_owner', $token);
        }

        return hash('sha256', $token);
    }
}
