<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTrustedOrigin
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        if (! $request->is('api/*')
            || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            || $request->is('api/payments/pakasir/webhook')) {
            return $next($request);
        }

        $origin = (string) $request->header('Origin', '');
        $allowedOrigins = config('cors.allowed_origins', []);
        if ($origin === '' || ! in_array($origin, $allowedOrigins, true)) {
            return response()->json(['message' => 'Origin permintaan tidak diizinkan.'], 403);
        }

        return $next($request);
    }
}
