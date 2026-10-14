<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    public function verify(string $token, ?string $ip = null): bool
    {
        $secret = (string) config('services.recaptcha.secret_key', '');

        // Bypass saat secret belum dikonfigurasi (mis. testing lokal) agar
        // alur auth tidak terkunci. Di production secret wajib diisi.
        if ($secret === '') {
            Log::warning('RECAPTCHA_SECRET_KEY belum dikonfigurasi, verifikasi dilewati.');
            return true;
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
