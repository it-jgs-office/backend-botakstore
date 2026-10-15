<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DigiflazzProductController extends Controller
{
    public function sync(): JsonResponse
    {
        if ($cooldown = $this->cooldownResponse()) {
            return $cooldown;
        }

        try {
            $products = $this->fetchProducts();
            Cache::put($this->cacheKey(), $products, now()->addMinutes(10));
            Cache::forget($this->cooldownKey());
        } catch (ConnectionException|RuntimeException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json(['data' => $products]);
    }

    public function mobileLegends(): JsonResponse
    {
        $cached = Cache::get($this->cacheKey());
        if (is_array($cached) && $cached !== []) {
            return response()->json(['data' => $cached]);
        }

        if ($cooldown = $this->cooldownResponse()) {
            return $cooldown;
        }

        try {
            $products = $this->fetchProducts();
            Cache::put($this->cacheKey(), $products, now()->addMinutes(10));
            Cache::forget($this->cooldownKey());
        } catch (ConnectionException|RuntimeException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json(['data' => $products]);
    }

    private function fetchProducts(): array
    {
        $username = (string) config('services.digiflazz.username');
        $production = filter_var(config('services.digiflazz.production'), FILTER_VALIDATE_BOOL);
        $apiKey = (string) ($production
            ? config('services.digiflazz.production_key')
            : config('services.digiflazz.development_key'));
        $apiKey = $apiKey !== '' ? $apiKey : (string) config('services.digiflazz.key');

        if ($username === '' || $apiKey === '') {
            throw new RuntimeException('Konfigurasi Digiflazz belum lengkap.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->post((string) config('services.digiflazz.price_list_url'), [
                'cmd' => 'prepaid',
                'username' => $username,
                'brand' => 'MOBILE LEGENDS',
                'sign' => md5($username.$apiKey.'pricelist'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Produk Mobile Legends sedang tidak tersedia.');
        }

        $data = $response->json('data');
        if (! is_array($data) || ! array_is_list($data)) {
            $message = is_array($data) ? (string) ($data['message'] ?? '') : '';
            if (str_contains(strtolower($message), 'limitasi')) {
                throw new RuntimeException('Batas sinkronisasi Digiflazz tercapai. Coba lagi beberapa saat lagi.');
            }
            throw new RuntimeException('Respons produk Digiflazz tidak valid.');
        }

        $products = collect($data)
            ->filter(function ($product): bool {
                if (! is_array($product)) {
                    return false;
                }

                $brand = strtolower((string) ($product['brand'] ?? ''));
                $name = strtolower((string) ($product['product_name'] ?? ''));

                return (str_contains($brand, 'mobile legends') || str_contains($name, 'mobile legends'))
                    && ! str_contains($name, 'cek username')
                    && (bool) ($product['buyer_product_status'] ?? false)
                    && (bool) ($product['seller_product_status'] ?? false)
                    && (int) ($product['price'] ?? 0) > 0
                    && (string) ($product['buyer_sku_code'] ?? '') !== '';
            })
            ->map(fn (array $product): array => [
                'id' => (string) $product['buyer_sku_code'],
                'label' => (string) $product['product_name'],
                'price' => (int) $product['price'],
                'type' => (string) ($product['type'] ?? ''),
                'description' => (string) ($product['desc'] ?? ''),
                'stock' => (bool) ($product['unlimited_stock'] ?? false)
                    ? null
                    : (int) ($product['stock'] ?? 0),
            ])
            ->unique('id')
            ->sortBy('price')
            ->values()
            ->all();

        if ($products === []) {
            throw new RuntimeException('Tidak ada produk Mobile Legends aktif pada akun Digiflazz.');
        }

        return $products;
    }

    private function errorResponse(RuntimeException|ConnectionException $exception): JsonResponse
    {
        $message = $exception instanceof ConnectionException
            ? 'Digiflazz tidak dapat dihubungi. Coba lagi beberapa saat lagi.'
            : $exception->getMessage();

        if (str_contains($message, 'Batas sinkronisasi')) {
            $retryAt = now()->addMinutes(5);
            Cache::put($this->cooldownKey(), $retryAt->timestamp, $retryAt);

            return response()->json([
                'message' => 'Batas sinkronisasi Digiflazz tercapai. Coba lagi dalam 5 menit.',
                'retry_after' => 300,
                'retry_at' => $retryAt->toIso8601String(),
            ], 429);
        }

        return response()->json(['message' => $message], 503);
    }

    private function cooldownResponse(): ?JsonResponse
    {
        $retryAt = (int) Cache::get($this->cooldownKey(), 0);
        $remaining = max(0, $retryAt - now()->timestamp);

        if ($remaining === 0) {
            Cache::forget($this->cooldownKey());
            return null;
        }

        return response()->json([
            'message' => sprintf(
                'Batas sinkronisasi Digiflazz tercapai. Coba lagi dalam %d menit %d detik.',
                intdiv($remaining, 60),
                $remaining % 60,
            ),
            'retry_after' => $remaining,
            'retry_at' => now()->addSeconds($remaining)->toIso8601String(),
        ], 429);
    }

    private function cacheKey(): string
    {
        $production = filter_var(config('services.digiflazz.production'), FILTER_VALIDATE_BOOL);

        return 'digiflazz:mobile-legends:v3:'.($production ? 'production' : 'development');
    }

    private function cooldownKey(): string
    {
        return $this->cacheKey().':cooldown';
    }
}
