<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;

class JwtService
{
    public function issue(User $user, ?int $ttlMinutes = null): array
    {
        $issuedAt = now()->timestamp;
        $expiresAt = now()->addMinutes($ttlMinutes ?? (int) config('jwt.ttl'))->timestamp;
        $payload = [
            'iss' => config('jwt.issuer'),
            'sub' => (string) $user->getKey(),
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => (string) Str::uuid(),
            'role' => $user->role,
        ];

        return [
            'token' => $this->encode($payload),
            'expires_in' => $expiresAt - $issuedAt,
        ];
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthenticationException('Token tidak valid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        $signature = $this->base64UrlDecode($encodedSignature);

        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || !is_array($payload)) {
            throw new AuthenticationException('Token tidak valid.');
        }

        $expected = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true);
        if (!hash_equals($expected, $signature)) {
            throw new AuthenticationException('Token tidak valid.');
        }

        if (!isset($payload['exp']) || (int) $payload['exp'] <= now()->timestamp) {
            throw new AuthenticationException('Sesi telah berakhir.');
        }

        if (($payload['iss'] ?? null) !== config('jwt.issuer')) {
            throw new AuthenticationException('Token tidak valid.');
        }

        return $payload;
    }

    private function encode(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true);

        return $encodedHeader.'.'.$encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET atau APP_KEY belum dikonfigurasi.');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new AuthenticationException('Token tidak valid.');
        }

        return $decoded;
    }
}
