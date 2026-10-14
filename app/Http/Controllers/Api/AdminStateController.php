<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreState;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStateController extends Controller
{
    public function publicState(): JsonResponse
    {
        $state = StoreState::query()->where('key', 'botakstore')->first();
        $payload = $state?->payload ?? [];

        return response()->json([
            'data' => array_intersect_key($payload, array_flip([
                'games',
                'services',
                'jokiPackages',
                'coupons',
                'banners',
                'settings',
            ])),
        ]);
    }

    public function publicOrder(string $orderId): JsonResponse
    {
        $state = StoreState::query()->where('key', 'botakstore')->first();
        $payload = $state?->payload ?? [];
        $order = collect($payload['orders'] ?? [])
            ->first(fn (array $item): bool => strtolower((string) ($item['id'] ?? '')) === strtolower($orderId)
                || strtolower((string) ($item['invoice'] ?? '')) === strtolower($orderId));

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $order['id'],
                'invoice' => $order['invoice'],
                'detail' => array_intersect_key($order['detail'] ?? [], array_flip([
                    'gameName', 'serviceName', 'fromRank', 'toRank', 'stars',
                ])),
                'paymentStatus' => $order['paymentStatus'],
                'status' => $order['status'],
                'progress' => $order['progress'],
                'createdAt' => $order['createdAt'],
            ],
        ]);
    }

    public function show(): JsonResponse
    {
        $state = StoreState::query()->where('key', 'botakstore')->first();

        return response()->json([
            'data' => $state?->payload,
            'updated_at' => $state?->updated_at,
        ]);
    }

    public function customers(): JsonResponse
    {
        $customers = User::query()
            ->where('role', 'customer')
            ->latest('created_at')
            ->get(['id', 'name', 'email', 'phone', 'role', 'created_at'])
            ->map(fn (User $user): array => [
                'id' => "api-{$user->id}",
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'role' => $user->role,
                'createdAt' => $user->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $customers]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['data' => ['required', 'array']]);
        $state = StoreState::query()->updateOrCreate(
            ['key' => 'botakstore'],
            ['payload' => $validated['data'], 'updated_by' => $request->user()->id],
        );

        return response()->json([
            'message' => 'Data admin tersimpan.',
            'updated_at' => $state->updated_at,
        ]);
    }
}
