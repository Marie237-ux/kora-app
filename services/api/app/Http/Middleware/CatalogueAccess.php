<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CatalogueAccess
{
    public function handle(Request $request, Closure $next)
    {
        $entity = $request->route('entity');
        $write = ! in_array($request->method(), ['GET', 'HEAD'], true);
        $permission =
            $entity === 'suppliers'
                ? ($write
                    ? 'purchases.write'
                    : 'purchases.read')
                : ($write
                    ? ($request->routeIs('catalogue.publish', 'catalogue.archive')
                        ? 'catalogue.publish'
                        : 'catalogue.write')
                    : 'catalogue.read');

        return app(RequirePermission::class)->handle($request, $next, $permission);
    }
}
