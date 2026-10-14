<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MobileLegendsController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'regex:/^\d+$/', 'min:5', 'max:20'],
            'server_id' => ['required', 'string', 'regex:/^\d+$/', 'min:1', 'max:10'],
        ], [
            'user_id.regex' => 'User ID hanya boleh berisi angka.',
            'server_id.regex' => 'Zone ID hanya boleh berisi angka.',
        ]);

        try {
            $response = Http::acceptJson()
                ->timeout(12)
                ->get((string) config('services.mobile_legends.validation_url'), [
                    'id' => $data['user_id'],
                    'server' => $data['server_id'],
                    'decode' => 'false',
                ]);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Layanan pengecekan akun sedang tidak tersedia. Coba lagi sebentar.',
            ], 503);
        }

        $result = $response->json();
        $username = is_array($result) ? rawurldecode((string) ($result['name'] ?? '')) : '';

        if (! $response->successful() || ! is_array($result) || ! ($result['success'] ?? false) || $username === '') {
            return response()->json([
                'message' => is_array($result) && isset($result['message'])
                    ? (string) $result['message']
                    : 'User ID atau Zone ID Mobile Legends tidak ditemukan.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'username' => $username,
                'country' => (string) ($result['country'] ?? 'Tidak diketahui'),
                'user_id' => (string) ($result['id'] ?? $data['user_id']),
                'server_id' => (string) ($result['server'] ?? $data['server_id']),
            ],
        ]);
    }
}
