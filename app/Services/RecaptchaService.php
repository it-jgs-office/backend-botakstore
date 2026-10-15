<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    public function verify(string $token, ?string $ip = null): bool
    {
        $secret = (string) config('services.recaptcha.secret_key', '');

        if ($secret === '') {
            Log::error('RECAPTCHA_SECRET_KEY belum dikonfigurasi. Login dan pendaftaran ditolak.');
            return false;
        }

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            if ($response->failed()) {
                Log::warning('Verifikasi reCAPTCHA gagal terhubung.', ['status' => $response->status()]);
                return false;
            }

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable $e) {
            Log::warning('Verifikasi reCAPTCHA error: '.$e->getMessage());
            return false;
        }
    }
}
