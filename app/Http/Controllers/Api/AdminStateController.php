<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreState;
use App\Models\StoreOperationState;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStateController extends Controller
{
    public function publicState(): JsonResponse
    {
        $state = StoreState::query()->where('key', 'botakstore')->first();
        $payload = $this->sanitizeCatalogPayload($state?->payload ?? []);

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
        $state = StoreOperationState::query()->where('key', 'botakstore')->first();
        $payload = $this->sanitizeOperations($state?->payload ?? []);
        $order = collect($payload['orders'] ?? [])
            ->first(fn (array $item): bool => strtolower((string) ($item['id'] ?? '')) === strtolower($orderId)
                || strtolower((string) ($item['invoice'] ?? '')) === strtolower($orderId));

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->publicOrderPayload($order)]);
    }

    public function recentOrders(): JsonResponse
    {
        $state = StoreOperationState::query()->where('key', 'botakstore')->first();
        $payload = $this->sanitizeOperations($state?->payload ?? []);
        $orders = collect($payload['orders'] ?? [])
            ->sortByDesc(fn (array $item): string => (string) ($item['createdAt'] ?? ''))
            ->take(10)
            ->map(fn (array $order): array => $this->publicOrderPayload($order))
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function customerData(Request $request): JsonResponse
    {
        $state = StoreOperationState::query()->where('key', 'botakstore')->first();
        $payload = $this->sanitizeOperations($state?->payload ?? []);
        $customerId = "api-{$request->user()->id}";
        $orders = collect($payload['orders'] ?? [])->where('customerId', $customerId)->values();
        $orderIds = $orders->pluck('id');

        return response()->json(['data' => [
            'orders' => $orders,
            'payments' => collect($payload['payments'] ?? [])->whereIn('orderId', $orderIds)->values(),
            'logs' => collect($payload['logs'] ?? [])->whereIn('orderId', $orderIds)->values(),
            'refunds' => collect($payload['refunds'] ?? [])->where('customerId', $customerId)->values(),
            'notifications' => collect($payload['notifications'] ?? [])->where('userId', $customerId)->values(),
        ]]);
    }

    public function show(): JsonResponse
    {
        $state = StoreState::query()->where('key', 'botakstore')->first();

        return response()->json([
            'data' => $state ? $this->sanitizeCatalogPayload($state->payload ?? []) : null,
            'updated_at' => $state?->updated_at,
        ]);
    }

    public function customers(): JsonResponse
    {
        $customers = User::query()
            ->where('role', 'customer')
            ->latest('created_at')
            ->get(['id', 'name', 'email', 'phone', 'role', 'created_at', 'profile_extras'])
            ->map(fn (User $user): array => [
                'id' => "api-{$user->id}",
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'role' => $user->role,
                'createdAt' => $user->created_at?->toISOString(),
                'profileExtras' => array_merge([
                    'avatar' => null,
                    'gameNickname' => '',
                    'gameUserId' => '',
                    'gameServerId' => '',
                    'notifOrder' => true,
                    'notifPromo' => true,
                ], $user->profile_extras ?? []),
            ]);

        return response()->json(['data' => $customers]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['data' => ['required', 'array']]);
        $state = StoreState::query()->updateOrCreate(
            ['key' => 'botakstore'],
            ['payload' => $this->sanitizeCatalogPayload($validated['data']), 'updated_by' => $request->user()->id],
        );

        return response()->json([
            'message' => 'Data admin tersimpan.',
            'updated_at' => $state->updated_at,
        ]);
    }

    public function operations(): JsonResponse
    {
        $state = StoreOperationState::query()->where('key', 'botakstore')->first();

        return response()->json([
            'data' => $state ? $this->sanitizeOperations($state->payload ?? []) : null,
            'updated_at' => $state?->updated_at,
        ]);
    }

    public function updateOperations(Request $request): JsonResponse
    {
        $validated = $request->validate(['data' => ['required', 'array']]);
        $state = StoreOperationState::query()->updateOrCreate(
            ['key' => 'botakstore'],
            ['payload' => $this->sanitizeOperations($validated['data']), 'updated_by' => $request->user()->id],
        );

        return response()->json([
            'message' => 'Data operasional tersimpan.',
            'updated_at' => $state->updated_at,
        ]);
    }

    private function publicOrderPayload(array $order): array
    {
        $publicReference = 'ORD-'.strtoupper(substr(hash('sha256', (string) ($order['id'] ?? '')), 0, 10));

        return [
            'id' => $publicReference,
            'invoice' => $publicReference,
            'detail' => array_intersect_key($order['detail'] ?? [], array_flip([
                'gameName', 'serviceName', 'fromRank', 'toRank', 'stars',
            ])),
            'paymentStatus' => $order['paymentStatus'],
            'status' => $order['status'],
            'progress' => $order['progress'],
            'createdAt' => $order['createdAt'],
        ];
    }

    private function sanitizeCatalogPayload(array $payload): array
    {
        $catalog = array_intersect_key($payload, array_flip([
            'users', 'games', 'services', 'jokiPackages', 'coupons', 'banners', 'settings',
        ]));
        $catalog['users'] = collect($catalog['users'] ?? [])
            ->reject(fn (array $user): bool => ($user['role'] ?? null) === 'jokier')
            ->values()->all();

        return $catalog;
    }

    private function sanitizeOperations(array $payload): array
    {
        $payload['orders'] = collect($payload['orders'] ?? [])
            ->reject(fn (array $order): bool => str_starts_with((string) ($order['invoice'] ?? ''), 'INV/202610'))
            ->map(function (array $order): array {
                $legacyProcessingStatuses = [
                    'waiting_assignment',
                    'waiting_confirmation',
                    'assigned',
                    'on_hold',
                    'pending_verification',
                ];

                if (($order['paymentStatus'] ?? null) === 'paid'
                    && in_array($order['status'] ?? null, $legacyProcessingStatuses, true)) {
                    $order['status'] = 'in_progress';
                }

                return $order;
            })
            ->values()->all();
        $orderIds = collect($payload['orders'])->pluck('id');
        $paidOrderIds = collect($payload['orders'])
            ->filter(fn (array $order): bool => ($order['paymentStatus'] ?? null) === 'paid')
            ->pluck('id')
            ->flip();
        foreach (['payments', 'logs', 'refunds'] as $key) {
            $payload[$key] = collect($payload[$key] ?? [])->whereIn('orderId', $orderIds)->values()->all();
        }
        $payload['logs'] = collect($payload['logs'])->map(function (array $log) use ($paidOrderIds): array {
            if ($paidOrderIds->has($log['orderId'] ?? null)
                && in_array($log['status'] ?? null, ['waiting_assignment', 'waiting_confirmation', 'assigned', 'on_hold', 'pending_verification'], true)) {
                $log['status'] = 'in_progress';
                $log['message'] = 'Pembayaran diterima. Pesanan sedang diproses.';
            }

            return $log;
        })->values()->all();
        $payload['notifications'] = collect($payload['notifications'] ?? [])
            ->reject(fn (array $notification): bool => str_starts_with((string) ($notification['userId'] ?? ''), 'u-j'))
            ->values()->all();

        return array_intersect_key($payload, array_flip([
            'orders', 'payments', 'logs', 'refunds', 'notifications',
        ]));
    }
}
