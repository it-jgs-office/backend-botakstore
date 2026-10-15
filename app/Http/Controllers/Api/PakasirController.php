<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PakasirTransaction;
use App\Models\StoreState;
use App\Models\StoreOperationState;
use App\Models\User;
use App\Services\JwtService;
use App\Services\JokiInvoiceService;
use App\Services\QrisDynamicService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PakasirController extends Controller
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly QrisDynamicService $qris,
        private readonly JokiInvoiceService $jokiInvoices,
    ) {}

    public function createJokiQris(Request $request): JsonResponse
    {
        $request->merge([
            'order_type' => 'joki',
            'payment_method' => 'qris',
        ]);

        return $this->create($request);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => ['required', 'in:topup,joki'],
            'item_id' => ['required', 'string', 'max:100'],
            // Harga, nama produk, dan game selalu ditentukan ulang dari katalog server.
            // Field ini dipertahankan agar klien lama tetap kompatibel, tetapi tidak dipercaya.
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:50000000'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
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

        if (filled($data['customer']['password'] ?? null)) {
            $data['customer']['password'] = Crypt::encryptString((string) $data['customer']['password']);
        }

        $catalog = StoreState::query()->where('key', 'botakstore')->first();
        $catalogPayload = $catalog?->payload ?? [];
        if (($catalogPayload['settings']['maintenance'] ?? false) === true) {
            return response()->json(['message' => 'Toko sedang dalam pemeliharaan. Transaksi baru sementara ditutup.'], 503);
        }

        $resolvedOrder = $this->resolveOrder($data, $catalogPayload);
        $data['item_id'] = $resolvedOrder['item_id'];
        $data['description'] = $resolvedOrder['description'];
        $data['customer']['game_id'] = $resolvedOrder['game_id'];
        $data['customer']['game_name'] = $resolvedOrder['game_name'];
        $baseAmount = $resolvedOrder['amount'];

        // Jangan pernah memakai customer_id dari browser. Bila pembeli masuk,
        // identitas pesanan diikat ke sesi JWT; pembelian tamu memakai ID anonim.
        if ($user = $this->optionalAuthenticatedUser($request)) {
            $data['customer']['customer_id'] = 'api-'.$user->id;
            $data['customer']['customer_name'] = $data['customer']['customer_name'] ?? $user->name;
        } else {
            unset($data['customer']['customer_id']);
        }

        [$amount, $discount, $couponCode] = $this->couponAmount(
            $baseAmount,
            (string) ($data['coupon_code'] ?? ''),
        );
        $data['customer']['coupon_code'] = $couponCode;
        $data['customer']['coupon_discount'] = $discount;
        $data['customer']['coupon_base_amount'] = $baseAmount;

        $isDynamicJokiQris = $data['order_type'] === 'joki';
        $dynamicQris = null;
        $serviceFee = 0;
        $totalPayment = $amount;
        if ($isDynamicJokiQris) {
            $data['payment_method'] = 'qris';
            $this->jokiInvoices->removeExpired();
            $serviceFee = $this->allocateJokiPaymentCode($amount);
            $totalPayment = $amount + $serviceFee;
            try {
                $dynamicQris = $this->qris->convert(
                    (string) ($catalogPayload['settings']['jokiQrisPayload'] ?? ''),
                    $totalPayment,
                );
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => 'QRIS joki belum siap: '.$exception->getMessage()], 422);
            }
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
            'amount' => $isDynamicJokiQris ? $totalPayment : $amount,
            'payment_method' => $data['payment_method'],
            'status' => 'pending',
            'is_sandbox' => $isDynamicJokiQris
                ? false
                : filter_var(config('services.pakasir.sandbox'), FILTER_VALIDATE_BOOL),
            'customer_data' => $data['customer'],
        ]);

        if ($isDynamicJokiQris && $dynamicQris !== null) {
            $txnId = 'JQR-'.Str::upper(Str::random(16));
            $expiresAt = $transaction->created_at->copy()->addMinutes(5)->toISOString();
            $payload = [
                'provider' => 'joki_dynamic_qris',
                'qr_string' => $dynamicQris,
                'total_payment' => $totalPayment,
                'fee' => $serviceFee,
                'expired_at' => $expiresAt,
            ];
            $transaction->update([
                'txn_id' => $txnId,
                'provider_payload' => $payload,
            ]);
            $this->storePendingOrder(
                $data,
                $orderId,
                $txnId,
                $totalPayment,
                $baseAmount,
                $discount,
                $couponCode,
            );

            return response()->json([
                'data' => [
                    'order_id' => $orderId,
                    'txn_id' => $txnId,
                    'amount' => $totalPayment,
                    'base_amount' => $baseAmount,
                    'discount' => $discount,
                    'coupon_code' => $couponCode,
                    'payment_method' => 'qris',
                    'fee' => $serviceFee,
                    'total_payment' => $totalPayment,
                    'expired_at' => $expiresAt,
                    'qr_string' => $dynamicQris,
                    'va_number' => null,
                    'payment_url' => null,
                    'is_sandbox' => $transaction->is_sandbox,
                    'is_dynamic_qris' => true,
                ],
            ], 201);
        }

        $slug = (string) config('services.pakasir.slug');
        $apiKey = (string) config('services.pakasir.api_key');
        if ($slug === '' || $apiKey === '') {
            $transaction->update(['status' => 'failed']);
            return response()->json(['message' => 'Konfigurasi Pakasir belum lengkap.'], 503);
        }

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
                    'amount' => $amount,
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
            $redirectUrl = rtrim($redirectUrl, '/').'/'.rawurlencode($orderId);
            $paymentUrl .= (str_contains($paymentUrl, '?') ? '&' : '?').http_build_query([
                'redirect' => $redirectUrl,
            ]);
            $payload['payment_link'] = $paymentUrl;
        }

        $transaction->update([
            'txn_id' => $txnId,
            'provider_payload' => $payload,
        ]);
        $this->storePendingOrder(
            $data,
            $orderId,
            $txnId,
            (int) ($payload['total_payment'] ?? $amount),
            $baseAmount,
            $discount,
            $couponCode,
        );

        return response()->json([
            'data' => [
                'order_id' => $orderId,
                'txn_id' => $txnId,
                'amount' => $amount,
                'base_amount' => $baseAmount,
                'discount' => $discount,
                'coupon_code' => $couponCode,
                'payment_method' => $data['payment_method'],
                'fee' => (int) ($payload['fee'] ?? 0),
                'total_payment' => (int) ($payload['total_payment'] ?? $amount),
                'expired_at' => $payload['expired_at'] ?? null,
                'qr_string' => $qrString !== '' ? $qrString : null,
                'va_number' => $vaNumber !== '' ? $vaNumber : null,
                'payment_url' => $paymentUrl,
                'is_sandbox' => filter_var(config('services.pakasir.sandbox'), FILTER_VALIDATE_BOOL),
                'is_dynamic_qris' => false,
            ],
        ], 201);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $transaction = PakasirTransaction::query()->where('order_id', $orderId)->first();

        if (! $transaction || $transaction->txn_id === null) {
            return response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
        }

        if (($transaction->provider_payload['provider'] ?? null) === 'joki_dynamic_qris') {
            if ($this->jokiInvoices->removePending($orderId, true) === 'removed') {
                return response()->json(['message' => 'Batas pembayaran 5 menit telah habis. Invoice otomatis dihapus.'], 410);
            }
            $transaction = PakasirTransaction::query()->where('order_id', $orderId)->first();
            if (! $transaction) {
                return response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
            }
        }

        if ($request->boolean('refresh')
            && $transaction->status === 'pending'
            && (($transaction->provider_payload['provider'] ?? null) !== 'joki_dynamic_qris')) {
            $slug = (string) config('services.pakasir.slug');
            $apiKey = (string) config('services.pakasir.api_key');

            if ($slug !== '' && $apiKey !== '') {
                try {
                    $response = Http::acceptJson()
                        ->withHeader('X-Api-Key', $apiKey)
                        ->timeout(10)
                        ->get(sprintf(
                            '%s/api/v2/transaction-status/%s/%s',
                            rtrim((string) config('services.pakasir.base_url'), '/'),
                            rawurlencode($slug),
                            rawurlencode($transaction->txn_id),
                        ));

                    $status = $response->json();
                    if ($response->successful()
                        && is_array($status)
                        && ($status['txn_id'] ?? null) === $transaction->txn_id
                        && ($status['order_id'] ?? null) === $transaction->order_id
                        && (int) ($status['amount'] ?? 0) === $transaction->amount
                        && filter_var($status['is_sandbox'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === $transaction->is_sandbox
                        && in_array($status['status'] ?? null, ['pending', 'completed', 'canceled'], true)
                        && $status['status'] !== $transaction->status) {
                        $transaction->update([
                            'status' => $status['status'],
                            'completed_at' => $status['status'] === 'completed'
                                ? ($status['completed_at'] ?? now())
                                : null,
                        ]);
                        $this->syncOrderPaymentStatus($transaction, $status['status']);
                    }
                } catch (ConnectionException) {
                    // Invoice lokal tetap dapat dibuka saat Pakasir tidak terjangkau.
                }
            }
        }

        $payload = $transaction->provider_payload ?? [];
        $customer = $transaction->customer_data ?? [];

        return response()->json([
            'data' => [
                'order_id' => $transaction->order_id,
                'txn_id' => $transaction->txn_id,
                'order_type' => $transaction->order_type,
                'description' => $transaction->description,
                'amount' => $transaction->amount,
                'base_amount' => (int) ($customer['coupon_base_amount'] ?? $transaction->amount),
                'discount' => (int) ($customer['coupon_discount'] ?? 0),
                'coupon_code' => $customer['coupon_code'] ?? null,
                'fee' => (int) ($payload['fee'] ?? 0),
                'total_payment' => (int) ($payload['total_payment'] ?? $transaction->amount),
                'payment_method' => $transaction->payment_method,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at?->toISOString(),
                'expired_at' => ($payload['payment_proof']['status'] ?? null) !== null
                    ? null
                    : (($payload['provider'] ?? null) === 'joki_dynamic_qris'
                        ? $this->jokiInvoices->expiresAt($transaction)->toISOString()
                        : ($payload['expired_at'] ?? null)),
                'qr_string' => $payload['qr_string'] ?? null,
                'va_number' => $payload['va_number'] ?? null,
                'payment_url' => $payload['payment_link'] ?? null,
                'is_sandbox' => $transaction->is_sandbox,
                'is_dynamic_qris' => ($payload['provider'] ?? null) === 'joki_dynamic_qris',
                'payment_proof_status' => $payload['payment_proof']['status'] ?? null,
                'payment_proof_uploaded_at' => $payload['payment_proof']['uploaded_at'] ?? null,
                'game_name' => $customer['game_name'] ?? null,
                'game_user_id' => $customer['user_id'] ?? null,
                'game_server_id' => $customer['server_id'] ?? null,
                'quantity' => $customer['quantity'] ?? null,
            ],
        ]);
    }

    public function uploadJokiPaymentProof(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'proof' => ['required', 'image', 'mimes:webp', 'max:5120'],
        ]);
        if ($this->jokiInvoices->removePending($orderId, true) === 'removed') {
            return response()->json(['message' => 'Batas pembayaran 5 menit telah habis. Invoice otomatis dihapus.'], 410);
        }
        return DB::transaction(function () use ($validated, $orderId): JsonResponse {
            $transaction = PakasirTransaction::query()
                ->where('order_id', $orderId)
                ->where('order_type', 'joki')
                ->lockForUpdate()
                ->first();

            if (! $transaction || ($transaction->provider_payload['provider'] ?? null) !== 'joki_dynamic_qris') {
                return response()->json(['message' => 'Invoice joki tidak ditemukan.'], 404);
            }
            if ($transaction->status !== 'pending') {
                return response()->json(['message' => 'Bukti pembayaran tidak dapat diunggah untuk invoice ini.'], 422);
            }
            if (($transaction->provider_payload['payment_proof']['status'] ?? null) === 'submitted') {
                return response()->json(['message' => 'Bukti pembayaran sudah dikirim dan sedang menunggu konfirmasi admin.'], 422);
            }
            if ($this->jokiInvoices->isExpired($transaction)) {
                $this->jokiInvoices->removePending($orderId, true);
                return response()->json(['message' => 'Batas pembayaran 5 menit telah habis. Invoice otomatis dihapus.'], 410);
            }

            $directory = public_path('uploads/botakstore/payment-proofs');
            if (! is_dir($directory)) mkdir($directory, 0755, true);
            $file = $validated['proof'];
            $filename = Str::uuid().'.'.$file->guessExtension();
            $file->move($directory, $filename);

            $payload = array_merge($transaction->provider_payload ?? [], [
                'payment_proof' => [
                    'status' => 'submitted',
                    'url' => url('/uploads/botakstore/payment-proofs/'.$filename),
                    'uploaded_at' => now()->toISOString(),
                ],
            ]);
            $transaction->update(['provider_payload' => $payload]);
            $this->markJokiProofSubmitted($transaction);

            return response()->json([
                'message' => 'Bukti pembayaran diterima. Menunggu konfirmasi admin.',
                'data' => [
                    'order_id' => $transaction->order_id,
                    'status' => 'waiting_confirmation',
                    'uploaded_at' => $payload['payment_proof']['uploaded_at'],
                ],
            ], 201);
        });
    }

    public function cancelJokiInvoice(string $orderId): JsonResponse
    {
        $result = $this->jokiInvoices->removePending($orderId);

        return match ($result) {
            'removed' => response()->json(['message' => 'Invoice berhasil dibatalkan dan dihapus.']),
            'not_allowed' => response()->json(['message' => 'Invoice yang sudah mengirim bukti pembayaran tidak dapat dibatalkan.'], 422),
            default => response()->json(['message' => 'Invoice tidak ditemukan.'], 404),
        };
    }

    public function jokiPaymentProofs(): JsonResponse
    {
        $proofs = PakasirTransaction::query()
            ->where('order_type', 'joki')
            ->where('status', 'pending')
            ->latest('updated_at')
            ->get()
            ->filter(fn (PakasirTransaction $transaction): bool => ($transaction->provider_payload['payment_proof']['status'] ?? null) === 'submitted')
            ->map(function (PakasirTransaction $transaction): array {
                $payload = $transaction->provider_payload ?? [];
                $customer = $transaction->customer_data ?? [];

                return [
                    'order_id' => $transaction->order_id,
                    'txn_id' => $transaction->txn_id,
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'fee' => (int) ($payload['fee'] ?? 0),
                    'total_payment' => (int) ($payload['total_payment'] ?? $transaction->amount),
                    'customer_name' => $customer['customer_name'] ?? $customer['username'] ?? $customer['nickname'] ?? 'Pelanggan',
                    'customer_phone' => $customer['whatsapp'] ?? '',
                    'proof_url' => $payload['payment_proof']['url'],
                    'uploaded_at' => $payload['payment_proof']['uploaded_at'],
                ];
            })
            ->values();

        return response()->json(['data' => $proofs]);
    }

    public function confirmJokiPayment(Request $request, string $orderId): JsonResponse
    {
        $transaction = PakasirTransaction::query()
            ->where('order_id', $orderId)
            ->where('order_type', 'joki')
            ->first();

        if (! $transaction || ($transaction->provider_payload['provider'] ?? null) !== 'joki_dynamic_qris') {
            return response()->json(['message' => 'Invoice joki tidak ditemukan.'], 404);
        }
        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'Pembayaran invoice ini sudah diproses.'], 422);
        }
        if (($transaction->provider_payload['payment_proof']['status'] ?? null) !== 'submitted') {
            return response()->json(['message' => 'Bukti pembayaran belum diunggah pelanggan.'], 422);
        }

        $proofUrl = (string) ($transaction->provider_payload['payment_proof']['url'] ?? '');
        DB::transaction(function () use ($transaction, $request): void {
            $transaction->update([
                'status' => 'completed',
                'completed_at' => now(),
                'provider_payload' => array_merge($transaction->provider_payload ?? [], [
                    'payment_proof' => array_merge($transaction->provider_payload['payment_proof'] ?? [], [
                        'status' => 'confirmed',
                        'confirmed_at' => now()->toISOString(),
                        'confirmed_by' => $request->user()->id,
                    ]),
                ]),
            ]);
            $this->syncOrderPaymentStatus($transaction, 'completed');
        });

        $proofPath = (string) parse_url($proofUrl, PHP_URL_PATH);
        $photoDeleted = false;
        if (preg_match('~(?:^|/)uploads/botakstore/payment-proofs/([0-9a-f-]{36}\.webp)$~i', $proofPath, $matches)) {
            $filePath = public_path('uploads/botakstore/payment-proofs/'.$matches[1]);
            try {
                $photoDeleted = ! File::exists($filePath) || File::delete($filePath);
            } catch (\Throwable $exception) {
                Log::warning('Bukti pembayaran joki gagal dihapus.', [
                    'order_id' => $orderId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($photoDeleted) {
            $payload = $transaction->provider_payload ?? [];
            $payload['payment_proof']['url'] = null;
            $payload['payment_proof']['deleted_at'] = now()->toISOString();
            $transaction->update(['provider_payload' => $payload]);
        }

        return response()->json(['message' => $photoDeleted
            ? 'Pembayaran dikonfirmasi. Foto bukti transfer dihapus dan pesanan joki sedang diproses.'
            : 'Pembayaran dikonfirmasi dan pesanan joki sedang diproses, tetapi foto bukti transfer belum berhasil dihapus.']);
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

        if ($transaction->status === 'completed' && $data['status'] !== 'completed') {
            return response()->json(['message' => 'Status pembayaran sudah selesai.']);
        }

        $transaction->update([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'completed'
                ? ($data['completed_at'] ?? now())
                : null,
            'provider_payload' => array_merge($transaction->provider_payload ?? [], ['webhook' => $data]),
        ]);
        $this->syncOrderPaymentStatus($transaction, $data['status']);

        return response()->json(['message' => 'Webhook diterima.']);
    }

    public function simulate(string $orderId): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        $transaction = PakasirTransaction::query()->where('order_id', $orderId)->first();

        if (! $transaction) {
            return response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($transaction->order_type === 'joki'
            || ($transaction->provider_payload['provider'] ?? null) === 'joki_dynamic_qris') {
            return response()->json(['message' => 'Pembayaran joki dikonfirmasi manual oleh admin.'], 403);
        }

        if (! $transaction->is_sandbox) {
            return response()->json(['message' => 'Simulasi hanya tersedia pada mode uji coba.'], 403);
        }

        if ($transaction->status === 'completed') {
            return response()->json(['message' => 'Pembayaran sudah berhasil disimulasikan.']);
        }

        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'Invoice ini sudah tidak dapat disimulasikan.'], 422);
        }

        $transaction->update([
            'status' => 'completed',
            'completed_at' => now(),
            'provider_payload' => array_merge($transaction->provider_payload ?? [], [
                'simulation' => ['status' => 'completed', 'at' => now()->toISOString()],
            ]),
        ]);
        $this->syncOrderPaymentStatus($transaction, 'completed');

        return response()->json(['message' => 'Pembayaran uji coba berhasil disimulasikan.']);
    }

    private function storePendingOrder(array $data, string $orderId, string $transactionId, int $totalPayment, int $baseAmount, int $discount, ?string $couponCode): void
    {
        $customer = $data['customer'];
        $paymentMethod = $data['payment_method'] === 'qris'
            ? 'qris'
            : (str_ends_with($data['payment_method'], '_va') ? 'va' : 'ewallet');
        $customerId = (string) ($customer['customer_id'] ?? 'guest-'.substr(sha1(($customer['email'] ?? '').($customer['whatsapp'] ?? '')), 0, 12));
        $customerName = (string) ($customer['customer_name'] ?? $customer['username'] ?? $customer['nickname'] ?? 'Pelanggan');
        $now = now()->toISOString();
        DB::transaction(function () use ($data, $orderId, $transactionId, $totalPayment, $baseAmount, $discount, $couponCode, $customer, $paymentMethod, $customerId, $customerName, $now): void {
            $state = StoreOperationState::query()
                ->lockForUpdate()
                ->where('key', 'botakstore')
                ->first();
            if (! $state) {
                $state = StoreOperationState::query()->create(['key' => 'botakstore', 'payload' => []]);
            }

            $payload = $state->payload ?? [];
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
                    'base' => $baseAmount,
                    'addons' => [],
                    'discount' => $discount,
                    'couponCode' => $couponCode,
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
            $state->payload = $payload;
            $state->save();
        });
    }

    private function markJokiProofSubmitted(PakasirTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $state = StoreOperationState::query()
                ->lockForUpdate()
                ->where('key', 'botakstore')
                ->first();
            if (! $state) return;

            $payload = $state->payload ?? [];
            $now = now()->toISOString();
            $payload['orders'] = collect($payload['orders'] ?? [])->map(function (array $order) use ($transaction, $now): array {
                if (($order['id'] ?? null) !== $transaction->order_id) return $order;

                return [...$order, 'status' => 'waiting_confirmation', 'updatedAt' => $now];
            })->values()->all();
            $payload['logs'] = [
                [
                    'id' => 'log-proof-'.$transaction->order_id,
                    'orderId' => $transaction->order_id,
                    'status' => 'waiting_confirmation',
                    'message' => 'Bukti pembayaran diunggah. Menunggu konfirmasi admin.',
                    'by' => 'customer',
                    'createdAt' => $now,
                ],
                ...($payload['logs'] ?? []),
            ];
            $state->payload = $payload;
            $state->save();
        });
    }

    private function syncOrderPaymentStatus(PakasirTransaction $transaction, string $paymentStatus): void
    {
        $paid = $paymentStatus === 'completed';
        DB::transaction(function () use ($transaction, $paymentStatus, $paid): void {
            $state = StoreOperationState::query()
                ->lockForUpdate()
                ->where('key', 'botakstore')
                ->first();
            if (! $state) return;

            $payload = $state->payload ?? [];
            $payload['orders'] = collect($payload['orders'] ?? [])->map(function (array $order) use ($transaction, $paymentStatus, $paid): array {
            if (($order['id'] ?? null) !== $transaction->order_id) return $order;
            return [
                ...$order,
                'paymentStatus' => $paymentStatus === 'pending' ? 'pending' : ($paid ? 'paid' : 'failed'),
                'status' => $paid ? 'in_progress' : 'waiting_payment',
                'updatedAt' => now()->toISOString(),
            ];
            })->values()->all();
            $payload['payments'] = collect($payload['payments'] ?? [])->map(function (array $payment) use ($transaction, $paymentStatus, $paid): array {
            if (($payment['orderId'] ?? null) !== $transaction->order_id) return $payment;
            return [...$payment, 'status' => $paymentStatus === 'pending' ? 'pending' : ($paid ? 'paid' : 'failed')];
            })->values()->all();
            $state->payload = $payload;
            $state->save();
        });

        if ($paid) $this->redeemCoupon($transaction);
    }

    private function couponAmount(int $subtotal, string $rawCode): array
    {
        $code = strtoupper(trim($rawCode));
        if ($code === '') return [$subtotal, 0, null];

        $state = StoreState::query()->where('key', 'botakstore')->first();
        $payload = $state?->payload ?? [];
        $coupon = collect($payload['coupons'] ?? [])->first(
            fn (array $item): bool => strtoupper((string) ($item['code'] ?? '')) === $code,
        );
        if (! $coupon || ! ($coupon['active'] ?? false)) abort(422, 'Kode voucher tidak ditemukan atau tidak aktif.');
        if ((int) ($coupon['used'] ?? 0) >= (int) ($coupon['quota'] ?? 0)) abort(422, 'Kuota voucher sudah habis.');
        if (strtotime((string) ($coupon['expiresAt'] ?? '')) < now()->startOfDay()->timestamp) abort(422, 'Voucher sudah kedaluwarsa.');
        if ($subtotal < (int) ($coupon['minOrder'] ?? 0)) abort(422, 'Belum memenuhi minimal transaksi voucher.');

        $discount = ($coupon['type'] ?? null) === 'percent'
            ? (int) round($subtotal * (int) ($coupon['value'] ?? 0) / 100)
            : (int) ($coupon['value'] ?? 0);
        $discount = min($discount, $subtotal);
        $amount = $subtotal - $discount;
        if ($amount < 500) abort(422, 'Total setelah diskon minimal Rp500.');

        return [$amount, $discount, $code];
    }

    private function redeemCoupon(PakasirTransaction $transaction): void
    {
        $customer = $transaction->customer_data ?? [];
        $code = strtoupper(trim((string) ($customer['coupon_code'] ?? '')));
        if ($code === '' || ($customer['coupon_redeemed'] ?? false)) return;

        $redeemed = DB::transaction(function () use ($code): bool {
            $state = StoreState::query()
                ->lockForUpdate()
                ->where('key', 'botakstore')
                ->first();
            if (! $state) return false;

            $payload = $state->payload ?? [];
            $redeemed = false;
            $payload['coupons'] = collect($payload['coupons'] ?? [])->map(function (array $coupon) use ($code, &$redeemed): array {
            if (! $redeemed
                && strtoupper((string) ($coupon['code'] ?? '')) === $code
                && ($coupon['active'] ?? false)
                && (int) ($coupon['used'] ?? 0) < (int) ($coupon['quota'] ?? 0)) {
                $coupon['used'] = (int) ($coupon['used'] ?? 0) + 1;
                $redeemed = true;
            }
            return $coupon;
            })->values()->all();
            if (! $redeemed) return false;

            $state->payload = $payload;
            $state->save();
            return true;
        });
        if (! $redeemed) return;

        $customer['coupon_redeemed'] = true;
        $transaction->customer_data = $customer;
        $transaction->save();
    }

    private function resolveOrder(array $data, array $catalog): array
    {
        $game = collect($catalog['games'] ?? [])->first(
            fn (array $item): bool => ($item['slug'] ?? null) === 'mobile-legends',
        );
        if (! $game) {
            abort(503, 'Katalog Mobile Legends belum tersedia.');
        }

        if ($data['order_type'] === 'joki') {
            $package = collect($catalog['jokiPackages'] ?? [])->first(fn (array $item): bool =>
                (string) ($item['id'] ?? '') === (string) $data['item_id']
                && ($item['gameSlug'] ?? null) === 'mobile-legends'
                && ($item['status'] ?? 'inactive') === 'active',
            );
            if (! $package) {
                abort(422, 'Paket joki tidak tersedia.');
            }

            $quantity = (int) ($data['customer']['quantity'] ?? 1);
            $perStar = (bool) ($package['perStar'] ?? false);
            $amount = (int) ($package['price'] ?? 0) * ($perStar ? $quantity : 1);
            if ($amount < 500) {
                abort(422, 'Harga paket joki tidak valid.');
            }

            return [
                'item_id' => (string) $package['id'],
                'description' => (string) ($package['name'] ?? 'Joki Mobile Legends'),
                'amount' => $amount,
                'game_id' => (string) ($game['id'] ?? 'g-mlbb'),
                'game_name' => (string) ($game['name'] ?? 'Mobile Legends'),
            ];
        }

        $production = filter_var(config('services.digiflazz.production'), FILTER_VALIDATE_BOOL);
        $products = Cache::get('digiflazz:mobile-legends:v3:'.($production ? 'production' : 'development'));
        if (! is_array($products) || $products === []) {
            abort(503, 'Katalog Top Up sedang disiapkan. Silakan muat ulang beberapa saat lagi.');
        }
        $product = collect($products)->first(
            fn (array $item): bool => (string) ($item['id'] ?? '') === (string) $data['item_id'],
        );
        if (! $product) {
            abort(422, 'Produk Top Up tidak tersedia atau harga sudah berubah.');
        }

        $settings = $catalog['settings'] ?? [];
        $markupPercent = max(0, (float) ($settings['topupMarkupPercent'] ?? 0));
        $markupFlat = max(0, (int) ($settings['topupMarkupFlat'] ?? 0));
        $amount = (int) round((int) $product['price'] * (1 + ($markupPercent / 100))) + $markupFlat;
        if ($amount < 500) {
            abort(422, 'Harga produk Top Up tidak valid.');
        }

        return [
            'item_id' => (string) $product['id'],
            'description' => 'Top Up Mobile Legends - '.(string) $product['label'],
            'amount' => $amount,
            'game_id' => (string) ($game['id'] ?? 'g-mlbb'),
            'game_name' => (string) ($game['name'] ?? 'Mobile Legends'),
        ];
    }

    private function optionalAuthenticatedUser(Request $request): ?User
    {
        $token = $request->bearerToken() ?: $request->cookie((string) config('jwt.cookie'));
        if (! $token) return null;

        try {
            $payload = $this->jwt->decode($token);
            return User::query()->find($payload['sub'] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function allocateJokiPaymentCode(int $subtotal): int
    {
        $usedAmounts = PakasirTransaction::query()
            ->where('order_type', 'joki')
            ->where('status', 'pending')
            ->pluck('amount')
            ->map(fn (int $amount): int => $amount)
            ->flip();
        $start = random_int(1, 999);

        for ($offset = 0; $offset < 999; $offset++) {
            $fee = (($start + $offset - 1) % 999) + 1;
            if (! $usedAmounts->has($subtotal + $fee)) return $fee;
        }

        // Sangat jarang terjadi; tetap gunakan rentang berbeda agar nominal tidak sama.
        return random_int(1000, 9999);
    }
}
