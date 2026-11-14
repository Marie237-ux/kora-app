<?php

namespace App\Http\Controllers;

use App\Domain\Shared\CommandBus;
use Illuminate\Http\Request;

abstract class OperationalController extends Controller
{
    protected function command(Request $request, string $name, array $data, callable $work, int $status = 200)
    {
        $result = app(CommandBus::class)->execute(
            $request->user(),
            $name,
            $request->header('Idempotency-Key'),
            $data,
            fn ($id) => $work($data, $id),
        );

        return response()->json(['data' => $result], $status);
    }

    protected function page($query, Request $request, callable $map): array
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = $query->paginate(24);

        return [
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    protected function allocationRules(): array
    {
        return [
            'allocations' => ['present', 'array', 'max:50'],
            'allocations.*' => ['array:obligation_id,amount'],
            'allocations.*.obligation_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'string'],
        ];
    }
}
