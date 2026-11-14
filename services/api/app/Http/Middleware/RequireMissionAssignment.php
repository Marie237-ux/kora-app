<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rechecks the current assignment before an idempotency receipt can replay a courier's mission or collection
 * data: a reassigned or unknown task looks absent to the courier, even through a stored response.
 */
final class RequireMissionAssignment
{
    public function handle(Request $request, Closure $next, string $table = 'delivery_missions')
    {
        abort_unless(in_array($table, ['delivery_missions', 'supplier_collections', 'after_sales_trips'], true), 500);
        $id = $request->route('id');
        abort_unless(
            $id && DB::table($table)->where('id', $id)->where('courier_id', $request->user()->id)->exists(),
            404,
        );

        return $next($request);
    }
}
