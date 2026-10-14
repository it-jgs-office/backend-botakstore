<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                throw new AuthenticationException('Token tidak ditemukan.');
            }

            $payload = $this->jwt->decode($token);
            $user = User::query()->find($payload['sub'] ?? null);
            if (!$user) {
                throw new AuthenticationException('Akun tidak ditemukan.');
            }

            $request->setUserResolver(fn () => $user);
        } catch (AuthenticationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return $next($request);
    }
}
