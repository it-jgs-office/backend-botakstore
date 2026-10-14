<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PakasirTransaction;
use App\Models\StoreState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PakasirController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => ['required', 'in:topup,joki'],
            'item_id' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:500', 'max:50000000'],
            'payment_method' => ['required', 'in:payment_link,qris,bri_va,bni_va,cimb_niaga_va,danamon_va,maybank_va,bnc_va,bsi_va,muamalat_va,ocbc_va'],
            'customer' => ['required', 'array'],
            'customer.whatsapp' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            'customer.user_id' => ['nullable', 'string', 'max:100'],
            'customer.server_id' => ['nullable', 'string', 'max:50'],
            'customer.username' => ['nullable', 'string', 'max:150'],
            'customer.country' => ['nullable', 'string', 'max:100'],
            'customer.account_id' => ['nullable', 'string', 'max:190'],
            'customer.password' => ['nullable', 'string', 'max:255'],
            'customer.login_method' => ['nullable', 'string', 'max:100'],
            'customer.nickname' => ['nullable', 'string', 'max:100'],
            'customer.heroes' => ['nullable', 'string', 'max:500'],
            'customer.note' => ['nullable', 'string', 'max:500'],
            'customer.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'customer.customer_id' => ['nullable', 'string', 'max:100'],
            'customer.customer_name' => ['nullable', 'string', 'max:150'],
            'customer.game_id' => ['nullable', 'string', 'max:100'],
            'customer.game_name' => ['nullable', 'string', 'max:150'],
        ]);

        $slug = (string) config('services.pakasir.slug');
        $apiKey = (string) config('services.pakasir.api_key');
        if ($slug === '' || $apiKey === '') {
            return response()->json(['message' => 'Konfigurasi Pakasir belum lengkap.'], 503);
        }

        $orderId = sprintf(
            'BS-%s-%s-%s',
            strtoupper($data['order_type']),
            now()->format('ymdHis'),
            Str::upper(Str::random(5)),
        );

        $transaction = PakasirTransaction::query()->create([
            'order_id' => $orderId,
            'order_type' => $data['order_type'],
            'item_id' => $data['item_id'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'status' => 'pending',
            'is_sandbox' => filter_var(config('services.pakasir.sandbox'), FILTER_VALIDATE_BOOL),
            'customer_data' => $data['customer'],
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeader('X-Api-Key', $apiKey)
                ->timeout(20)
                ->post(sprintf(
                    '%s/api/v2/create-transaction/%s/%s',
                    rtrim((string) config('services.pakasir.base_url'), '/'),
                    rawurlencode($slug),
                    rawurlencode($orderId),
                ), [
                    'method' => $data['payment_method'],
                    'amount' => $data['amount'],
                ]);
        } catch (ConnectionException) {
            $transaction->update(['status' => 'failed']);
            return response()->json(['message' => 'Pakasir tidak dapat dihubungi.'], 503);
        }

        $payload = $response->json();
        $paymentUrl = is_array($payload) ? (string) ($payload['payment_link'] ?? '') : '';
        $qrString = is_array($payload) ? (string) ($payload['qr_string'] ?? '') : '';
        $vaNumber = is_array($payload) ? (string) ($payload['va_number'] ?? '') : '';
        $txnId = is_array($payload) ? (string) ($payload['txn_id'] ?? '') : '';

        if (! $response->successful() || $txnId === '' || ($paymentUrl === '' && $qrString === '' && $vaNumber === '')) {
            $transaction->update([
                'status' => 'failed',
                'provider_payload' => is_array($payload) ? $payload : null,
            ]);
            return response()->json([
                'message' => is_array($payload) && isset($payload['message'])
                    ? (string) $payload['message']
                    : 'Pembayaran Pakasir gagal dibuat.',
            ], 422);
        }

        $redirectUrl = (string) config('services.pakasir.redirect_url');
        if ($paymentUrl !== '' && $redirectUrl !== '') {
            $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?').'order_id='.rawurlencode($orderId);
            $paymentUrl .= (str_contains($paymentUrl, '?') ? '&' : '?').http_build_query([
                'redirect' => $redirectUrl,
            ]);
        }

        $transaction->update([
            'txn_id' => $txnId,
            'provider_payload' => $payload,
        ]);
        $this->storePendingOrder(
            $data,
            $orderId,
            $txnId,
            (int) ($payload['total_payment'] ?? $data['amount']),
        );

        return response()->json([
            'data' => [
                'order_id' => $orderId,
                'txn_id' => $txnId,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'fee' => (int) ($payload['fee'] ?? 0),
                'total_payment' => (int) ($payload['total_payment'] ?? $data['amount']),
                'expired_at' => $payload['expired_at'] ?? null,
                'qr_string' => $qrString !== '' ? $qrString : null,
                'va_number' => $vaNumber !== '' ? $vaNumber : null,
                'payment_url' => $paymentUrl,
                'is_sandbox' => filter_var(config('services.pakasir.sandbox'), FILTER_VALIDATE_BOOL),
            ],
        ], 201);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('services.pakasir.webhook_secret');
        $providedSecret = (string) $request->header('X-Secret', '');
        if ($secret === '' || $providedSecret === '' || ! hash_equals($secret, $providedSecret)) {
            return response()->json(['message' => 'Webhook tidak valid.'], 401);
        }

        $data = $request->validate([
            'txn_id' => ['required', 'string', 'max:100'],
            'order_id' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:500'],
            'status' => ['required', 'in:pending,completed,canceled'],
            'is_sandbox' => ['required', 'boolean'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $transaction = PakasirTransaction::query()
            ->where('order_id', $data['order_id'])
            ->first();

        if (! $transaction
            || $transaction->txn_id !== $data['txn_id']
            || $transaction->amount !== (int) $data['amount']
            || $transaction->is_sandbox !== (bool) $data['is_sandbox']) {
            return response()->json(['message' => 'Data transaksi tidak cocok.'], 422);
        }

        $transaction->update([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'completed'
                ? ($data['completed_at'] ?? now())
                : null,
            'provider_payload' => $data,
        ]);
        $this->syncOrderPaymentStatus($transaction, $data['status']);

        return response()->json(['message' => 'Webhook diterima.']);
    }

    private function storePendingOrder(array $data, string $orderId, string $transactionId, int $totalPayment): void
    {
        $customer = $data['customer'];
        $paymentMethod = $data['payment_method'] === 'qris'
            ? 'qris'
            : (str_ends_with($data['payment_method'], '_va') ? 'va' : 'ewallet');
        $customerId = (string) ($customer['customer_id'] ?? 'guest-'.substr(sha1(($customer['email'] ?? '').($customer['whatsapp'] ?? '')), 0, 12));
        $customerName = (string) ($customer['customer_name'] ?? $customer['username'] ?? $customer['nickname'] ?? 'Pelanggan');
        $now = now()->toISOString();
        $state = StoreState::query()->where('key', 'botakstore')->first();
        $payload = $state?->payload ?? [];
        $orders = is_array($payload['orders'] ?? null) ? $payload['orders'] : [];
        $payments = is_array($payload['payments'] ?? null) ? $payload['payments'] : [];
        $logs = is_array($payload['logs'] ?? null) ? $payload['logs'] : [];

        if (! collect($orders)->contains(fn (array $order): bool => ($order['id'] ?? null) === $orderId)) {
            array_unshift($orders, [
                'id' => $orderId,
                'invoice' => $orderId,
                'customerId' => $customerId,
                'customerName' => $customerName,
                'customerEmail' => (string) ($customer['email'] ?? ''),
                'customerPhone' => (string) ($customer['whatsapp'] ?? ''),
                'detail' => [
                    'gameId' => (string) ($customer['game_id'] ?? ($data['order_type'] === 'topup' ? 'g-mlbb' : 'g-mlbb')),
                    'gameName' => (string) ($customer['game_name'] ?? ($data['order_type'] === 'topup' ? 'Mobile Legends' : 'Game')),
                    'serviceId' => (string) $data['item_id'],
                    'serviceName' => (string) $data['description'],
                    'serviceType' => 'per_star',
                    'stars' => $customer['quantity'] ?? null,
                    'gameUserId' => $customer['user_id'] ?? $customer['account_id'] ?? null,
                    'gameServerId' => $customer['server_id'] ?? null,
                    'modeLabel' => $customer['login_method'] ?? null,
                    'priority' => false,
                    'notes' => $customer['note'] ?? null,
                ],
                'price' => [
                    'base' => $totalPayment,
                    'addons' => [],
                    'discount' => 0,
                    'total' => $totalPayment,
                ],
                'paymentMethod' => $paymentMethod,
                'paymentStatus' => 'pending',
                'status' => 'waiting_payment',
                'progress' => 0,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
            array_unshift($payments, [
                'id' => $transactionId,
                'orderId' => $orderId,
                'method' => $paymentMethod,
                'amount' => $totalPayment,
                'status' => 'pending',
                'createdAt' => $now,
            ]);
            array_unshift($logs, [
                'id' => 'log-'.$orderId,
                'orderId' => $orderId,
                'status' => 'waiting_payment',
                'message' => 'Pesanan dibuat, menunggu pembayaran.',
                'by' => 'system',
                'createdAt' => $now,
            ]);
        }

        $payload['orders'] = $orders;
        $payload['payments'] = $payments;
        $payload['logs'] = $logs;
        StoreState::query()->updateOrCreate(['key' => 'botakstore'], ['payload' => $payload]);
    }

    private function syncOrderPaymentStatus(PakasirTransaction $transaction, string $paymentStatus): void
    {
        $state = StoreState::query()->where('key', 'botakstore')->first();
        if (! $state) return;

        $payload = $state->payload ?? [];
        $paid = $paymentStatus === 'completed';
        $payload['orders'] = collect($payload['orders'] ?? [])->map(function (array $order) use ($transaction, $paid): array {
            if (($order['id'] ?? null) !== $transaction->order_id) return $order;
            return [
                ...$order,
                'paymentStatus' => $paid ? 'paid' : 'failed',
                'status' => $paid ? 'waiting_assignment' : 'waiting_payment',
                'updatedAt' => now()->toISOString(),
            ];
        })->values()->all();
        $payload['payments'] = collect($payload['payments'] ?? [])->map(function (array $payment) use ($transaction, $paid): array {
            if (($payment['orderId'] ?? null) !== $transaction->order_id) return $payment;
            return [...$payment, 'status' => $paid ? 'paid' : 'failed'];
        })->values()->all();
        $state->payload = $payload;
        $state->save();
    }
}
