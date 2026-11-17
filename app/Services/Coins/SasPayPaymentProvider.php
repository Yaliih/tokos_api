<?php

namespace App\Services\Coins;

use App\Exceptions\CompetitionException;
use App\Models\CoinPurchase;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Achat de coins par SasPay (https://saspay.me) : mobile money RDC, en CDF.
 *  - POST /payments/softpay/       → demande de paiement envoyée sur le téléphone (M-Pesa, Orange Money, Airtel Money)
 *  - GET  /payments/{id}/verify/   → état réel d'un paiement
 *  - Webhook POST /api/payments/webhook/saspay : signature HMAC-SHA256 de « {timestamp}.{corps brut} »
 *    (en-têtes X-Webhook-Signature et X-Webhook-Timestamp).
 * La clé et le secret se saisissent dans l'admin (Réglages → Paiements). Une clé sk_test_… = aucun argent réel.
 * Même intégration que Tuendea.
 */
class SasPayPaymentProvider implements PaymentProvider
{
    public function __construct(private CoinService $coins)
    {
    }

    public function start(CoinPurchase $purchase): ?string
    {
        $network = config('coins.saspay.networks.'.$purchase->operator);
        if (! $network) {
            $this->fail($purchase, 'Choisis M-Pesa, Orange Money ou Airtel Money.');
        }
        $phone = self::normalizePhone($purchase->phone);
        if (! $phone || strlen($phone) !== 12) {
            $this->fail($purchase, 'Numéro mobile money invalide. Exemple : 0812345678.');
        }
        $user = $purchase->user;
        $response = $this->request('post', '/payments/softpay/', [
            'amount' => number_format((float) $purchase->price, 2, '.', ''),
            'currency' => 'CDF',
            'country' => 'CD',
            'network' => $network,
            'description' => $purchase->coins.' coins '.config('app.name'),
            'customer' => [
                'email' => filter_var($user?->email, FILTER_VALIDATE_EMAIL) ? $user->email : 'client'.$purchase->user_id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'sosa.social'),
                'first_name' => $user?->first_name ?: 'Client',
                'last_name' => $user?->last_name ?: config('app.name'),
                'phone' => '+'.$phone,
            ],
            'metadata' => ['reference' => 'coins-'.$purchase->id, 'purchase_id' => $purchase->id],
        ], 'coins-'.$purchase->id);
        $body = self::body($response);
        if (! $response->successful() || empty($body['id'])) {
            $this->fail($purchase, $this->errorMessage($response));
        }
        $purchase->forceFill(['provider_ref' => (string) $body['id']])->save();

        return match (strtoupper((string) ($body['status'] ?? 'PENDING'))) {
            'SUCCESS' => tap(null, fn () => $this->coins->completePurchase($purchase, (string) $body['id'])),
            'FAILED', 'CANCELLED' => $this->fail($purchase, $body['message'] ?? 'Paiement refusé.'),
            // Validation par code PIN sur le téléphone ; parfois une page de paiement
            default => filled($body['checkout_url'] ?? null) ? (string) $body['checkout_url'] : null,
        };
    }

    /** Relit l'état chez SasPay (quand le webhook n'est pas encore arrivé). Renvoie le nouveau statut. */
    public function verify(CoinPurchase $purchase): string
    {
        if ($purchase->status !== 'pending' || ! $purchase->provider_ref) {
            return $purchase->status;
        }
        try {
            $body = self::body($this->request('get', '/payments/'.$purchase->provider_ref.'/verify/'));
        } catch (\Throwable) {
            return $purchase->status;
        }
        $this->applyStatus($purchase, strtoupper((string) ($body['status'] ?? '')));

        return $purchase->fresh()->status;
    }

    /** Webhook SasPay : renvoie true si la signature est bonne (on répond 200 dans ce cas). */
    public function webhook(Request $request): bool
    {
        $secret = (string) config('coins.saspay.webhook_secret');
        $raw = $request->getContent();
        $ts = (string) $request->header('X-Webhook-Timestamp');
        $sig = strtolower((string) $request->header('X-Webhook-Signature'));
        if ($secret === '' || ! ctype_digit($ts) || abs(time() - (int) $ts) > 300
            || ! hash_equals(hash_hmac('sha256', $ts.'.'.$raw, $secret), $sig)) {
            return false;
        }
        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);
        $id = (string) ($data['id'] ?? '');
        if (! str_starts_with($event, 'transaction.') || $id === '') {
            return true; // webhook.test, etc.
        }
        $ref = (string) data_get($data, 'metadata.reference', '');
        $purchase = CoinPurchase::where('provider', 'saspay')->where('provider_ref', $id)->first()
            ?? (str_starts_with($ref, 'coins-') ? CoinPurchase::find((int) substr($ref, 6)) : null);
        if ($purchase) {
            $this->applyStatus($purchase, match ($event) {
                'transaction.success' => 'SUCCESS',
                'transaction.failed', 'transaction.cancelled' => 'FAILED',
                default => '',
            }, $id);
        }

        return true;
    }

    private function applyStatus(CoinPurchase $purchase, string $status, ?string $ref = null): void
    {
        if ($status === 'SUCCESS') {
            $this->coins->completePurchase($purchase, $ref ?? $purchase->provider_ref);
        } elseif (in_array($status, ['FAILED', 'CANCELLED'], true) && $purchase->status === 'pending') {
            $purchase->forceFill(['status' => 'failed'])->save();
        }
    }

    private function fail(CoinPurchase $purchase, string $message): never
    {
        $purchase->forceFill(['status' => 'failed'])->save();
        throw new CompetitionException($message);
    }

    private function request(string $verb, string $path, array $payload = [], ?string $idempotencyKey = null): Response
    {
        $base = rtrim((string) config('coins.saspay.base_url'), '/');
        try {
            $http = Http::withToken((string) config('coins.saspay.api_key'))->acceptJson()->asJson()->timeout(30);
            if ($idempotencyKey) {
                $http = $http->withHeaders(['Idempotency-Key' => 'tokos-'.$idempotencyKey]);
            }

            return $verb === 'get' ? $http->get($base.$path) : $http->post($base.$path, $payload);
        } catch (\Throwable $e) {
            Log::error('[saspay] '.$verb.' '.$path.' : '.$e->getMessage());
            throw new CompetitionException('Service de paiement momentanément indisponible. Réessaie.', 503);
        }
    }

    /** Les réponses SasPay peuvent être enveloppées dans « data ». */
    private static function body(Response $response): array
    {
        $json = $response->json() ?? [];

        return is_array($json['data'] ?? null) && ! isset($json['id']) ? $json['data'] : $json;
    }

    private function errorMessage(Response $response): string
    {
        if ($response->status() === 401) {
            Log::error('[saspay] clé API refusée (401)');

            return 'Paiement mal configuré. Réessaie plus tard.';
        }
        $json = $response->json() ?? [];
        $err = $json['error'] ?? $json;
        $msg = is_array($err) ? ($err['message'] ?? collect($err)->flatten()->first(fn ($x) => is_string($x))) : (string) $err;
        Log::warning('[saspay] refus '.$response->status(), ['body' => $json]);

        return is_string($msg) && $msg !== '' ? $msg : 'Le paiement a été refusé.';
    }

    /** Numéro au format international RDC (243XXXXXXXXX). */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = '243'.substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            $digits = '243'.$digits;
        }

        return $digits;
    }
}
