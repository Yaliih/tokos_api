<?php

namespace App\Http\Middleware;

use App\Support\Device;
use Closure;
use Illuminate\Http\Request;

/** Pose le cookie d'appareil s'il manque (2 ans). */
class EnsureDeviceId
{
    public function handle(Request $request, Closure $next)
    {
        $new = null;
        if (! Device::id($request)) {
            $new = Device::fresh();
            $request->attributes->set(Device::COOKIE, $new);
        }
        $response = $next($request);
        if ($new && method_exists($response, 'withCookie')) {
            $response->withCookie(cookie(Device::COOKIE, $new, 60 * 24 * 730, '/', null, null, true, false, 'Lax'));
        }

        return $response;
    }
}
