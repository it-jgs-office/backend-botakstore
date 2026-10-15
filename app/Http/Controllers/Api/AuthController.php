<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use App\Services\RecaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly RecaptchaService $recaptcha,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'recaptcha_token' => ['required', 'string', 'min:10'],
            'remember_browser' => ['nullable', 'boolean'],
        ], [
            'recaptcha_token.required' => 'Mohon centang verifikasi captcha terlebih dahulu.',
        ]);

        $this->assertCaptcha((string) $credentials['recaptcha_token'], $request->ip());

        $user = User::query()->where('email', strtolower($credentials['email']))->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau kata sandi salah.'],
            ]);
        }

        return $this->tokenResponse($user, 200, $request->boolean('remember_browser'));
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'min:10', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'recaptcha_token' => ['required', 'string', 'min:10'],
        ], [
            'recaptcha_token.required' => 'Mohon centang verifikasi captcha terlebih dahulu.',
        ]);

        $this->assertCaptcha((string) $data['recaptcha_token'], $request->ip());

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => 'customer',
        ]);

        return $this->tokenResponse($user, 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(): JsonResponse
    {
        return response()->json(['message' => 'Berhasil keluar.'])
            ->withCookie(Cookie::forget((string) config('jwt.cookie'), '/api'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);
        $request->user()->update($data);

        return response()->json(['user' => $this->userPayload($request->user()->fresh())]);
    }

    public function profileExtras(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_merge([
                'avatar' => null,
                'gameNickname' => '',
                'gameUserId' => '',
                'gameServerId' => '',
                'notifOrder' => true,
                'notifPromo' => true,
            ], $request->user()->profile_extras ?? []),
        ]);
    }

    public function updateProfileExtras(Request $request): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['nullable', 'string', 'max:2000000'],
            'gameNickname' => ['nullable', 'string', 'max:100'],
            'gameUserId' => ['nullable', 'string', 'max:100'],
            'gameServerId' => ['nullable', 'string', 'max:50'],
            'notifOrder' => ['required', 'boolean'],
            'notifPromo' => ['required', 'boolean'],
        ]);

        $request->user()->update(['profile_extras' => $data]);

        return response()->json(['data' => $data]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Kata sandi saat ini salah.'],
            ]);
        }

        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Kata sandi berhasil diubah.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($request->user()->role === 'admin') {
            return response()->json(['message' => 'Akun admin utama tidak dapat dihapus.'], 422);
        }

        $request->user()->delete();

        return response()->json(['message' => 'Akun berhasil dihapus.']);
    }

    private function assertCaptcha(string $token, ?string $ip): void
    {
        if (!$this->recaptcha->verify($token, $ip)) {
            throw ValidationException::withMessages([
                'recaptcha_token' => ['Verifikasi captcha gagal atau kedaluwarsa. Silakan centang ulang captcha.'],
            ]);
        }
    }

    private function tokenResponse(User $user, int $status = 200, bool $rememberBrowser = false): JsonResponse    {
        $ttl = $rememberBrowser
            ? (int) config('jwt.remember_ttl')
            : (int) config('jwt.ttl');
        $issued = $this->jwt->issue($user, $ttl);

        return response()->json([
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'user' => $this->userPayload($user),
        ], $status)->withCookie(cookie(
            (string) config('jwt.cookie'),
            $issued['token'],
            max(1, (int) ceil($issued['expires_in'] / 60)),
            '/api',
            null,
            (bool) config('jwt.cookie_secure'),
            true,
            false,
            (string) config('jwt.cookie_same_site'),
        ));
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'created_at' => $user->created_at,
        ];
    }
}
