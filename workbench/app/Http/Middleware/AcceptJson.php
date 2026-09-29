<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Middleware;

use Closure;

class AcceptJson
{
    public function handle($request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
