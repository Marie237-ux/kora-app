<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's unnamed limiter keys only on the user (or IP), so every throttled route shares one counter: a customer
 * who adds fifteen items could then be refused a rare action limited to fifteen per minute. Each route gets its
 * own counter here, still per user or IP. Named limiters are unaffected.
 */
final class RouteThrottle extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $request->method().' '.($route ? $route->uri() : $request->path());

        return parent::resolveRequestSignature($request).'|'.sha1($scope);
    }
}
