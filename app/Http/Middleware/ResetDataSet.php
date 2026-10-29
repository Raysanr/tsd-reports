<?php

namespace App\Http\Middleware;

use App\Support\DataSet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every request starts on TSD's own data — see DataSet::reset(). */
class ResetDataSet
{
    public function handle(Request $request, Closure $next): Response
    {
        DataSet::reset();

        return $next($request);
    }
}
