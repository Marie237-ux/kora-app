<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use App\Domain\Shared\LocalEventHandlers;
use App\Domain\Shared\OutboxProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OutboxController extends OperationalController
{
    public function index(Request $request, OutboxProcessor $processor)
    {
        $data = $request->validate(['state' => ['nullable', 'in:pending,processed,failed,unsupported']]);
        $q = DB::table('outbox_events')->orderByDesc('created_at')->orderBy('id');
        if (($data['state'] ?? null) === 'processed') {
            $q->whereNotNull('processed_at');
        }
        if (($data['state'] ?? null) === 'failed') {
            $q->whereNull('processed_at')->whereNotNull('failed_at');
        }
        if (in_array($data['state'] ?? null, ['pending', 'unsupported'], true)) {
            $q->whereNull('processed_at')->whereNull('failed_at');
            if ($data['state'] === 'pending') {
                $q->whereIn('type', LocalEventHandlers::types());
            } else {
                $q->whereNotIn('type', LocalEventHandlers::types());
            }
        }

        return $this->page($q, $request, fn ($r) => $processor->dto($r));
    }

    public function attempts(Request $request, string $id)
    {
        Records::row('outbox_events', $id, false);

        return $this->page(
            DB::table('outbox_attempts')->where('event_id', $id)->orderByDesc('attempt'),
            $request,
            fn ($r) => [
                'attempt' => (int) $r->attempt,
                'handler' => $r->handler,
                'result' => $r->result,
                'error_code' => $r->error_code,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function retry(Request $request, string $id, OutboxProcessor $processor)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->command(
            $request,
            'outbox.retry:'.$id,
            $data,
            fn ($d) => $processor->retry($id, $d['reason'], $request->user()),
        );
    }
}
