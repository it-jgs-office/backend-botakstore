<?php

namespace App\Services;

use App\Models\PakasirTransaction;
use App\Models\StoreOperationState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class JokiInvoiceService
{
    public function expiresAt(PakasirTransaction $transaction): CarbonImmutable
    {
        $value = $transaction->provider_payload['expired_at'] ?? null;

        return $value
            ? CarbonImmutable::parse($value)
            : CarbonImmutable::instance($transaction->created_at)->addMinutes(5);
    }

    public function isExpired(PakasirTransaction $transaction): bool
    {
        return $this->expiresAt($transaction)->isPast();
    }

    public function removePending(string $orderId, bool $onlyIfExpired = false): string
    {
        return DB::transaction(function () use ($orderId, $onlyIfExpired): string {
            $transaction = PakasirTransaction::query()
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $transaction || ($transaction->provider_payload['provider'] ?? null) !== 'joki_dynamic_qris') {
                return 'not_found';
            }
            if ($transaction->status !== 'pending'
                || ($transaction->provider_payload['payment_proof']['status'] ?? null) !== null) {
                return 'not_allowed';
            }
            if ($onlyIfExpired && ! $this->isExpired($transaction)) {
                return 'not_expired';
            }

            $state = StoreOperationState::query()
                ->where('key', 'botakstore')
                ->lockForUpdate()
                ->first();
            if ($state) {
                $payload = $state->payload ?? [];
                foreach (['orders', 'payments', 'logs', 'notifications', 'refunds'] as $key) {
                    $payload[$key] = collect($payload[$key] ?? [])
                        ->reject(fn ($item): bool => is_array($item)
                            && ($key === 'orders' ? ($item['id'] ?? null) : ($item['orderId'] ?? null)) === $orderId)
                        ->values()
                        ->all();
                }
                $state->payload = $payload;
                $state->save();
            }
            $transaction->delete();

            return 'removed';
        });
    }

    public function removeExpired(): void
    {
        PakasirTransaction::query()
            ->where('order_type', 'joki')
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->select(['id', 'order_id'])
            ->chunkById(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $this->removePending($transaction->order_id, true);
                }
            });
    }
}
