<?php

namespace Tests\Feature;

use App\Models\CoinPurchase;
use App\Models\User;
use App\Support\Features;
use App\Support\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Paiements : coupés par défaut, réglés dans l'admin, achat de coins par SasPay (mobile money). */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Payments::flush();
        parent::tearDown();
    }

    public function test_everything_paid_is_off_by_default(): void
    {
        $list = (require base_path('config/features.php'))['list'];
        foreach (['coins', 'buy_coins_web', 'buy_coins_app', 'paid_votes', 'withdrawals'] as $k) {
            $this->assertFalse($list[$k]['default'], $k);
        }
    }

    public function test_admin_sets_coin_price_and_packs_and_web_app_separately(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/paiements')->assertOk()->assertSee('SasPay');

        Payments::save(['coin_price' => 25, 'pack_sizes' => '100, 20, 500', 'popular' => 100, 'saspay_key' => 'sk_test_abc', 'saspay_secret' => 'whsec']);
        $this->assertSame([['code' => 'p20', 'coins' => 20, 'price' => 500], ['code' => 'p100', 'coins' => 100, 'price' => 2500, 'bonus' => 'Populaire'], ['code' => 'p500', 'coins' => 500, 'price' => 12500]], config('coins.packs'));
        $this->assertSame('saspay', config('coins.provider'));
        $this->assertSame('sk_test_abc', config('coins.saspay.api_key'));
        $this->assertStringNotContainsString('sk_test_abc', json_encode(\App\Models\Setting::find('payments')->value)); // chiffrée en base

        // Achat ouvert sur le site, fermé dans l'app
        Features::save(array_merge(Features::all(), ['buy_coins_web' => true, 'buy_coins_app' => false]));
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $this->getJson('/api/app/wallet')->assertOk()->assertJsonPath('data.can_buy', false)->assertJsonPath('data.packs', []);
        $this->actingAs($u)->get('/portefeuille')->assertOk()->assertSee('Orange Money')->assertSee('data-buy-form', false);
    }

    public function test_saspay_purchase_then_signed_webhook_credits_coins(): void
    {
        Payments::save(['coin_price' => 20, 'pack_sizes' => '50', 'saspay_key' => 'sk_test_abc', 'saspay_secret' => 'whsec']);
        Http::fake(['*/payments/softpay/' => Http::response(['id' => 'tx_123', 'status' => 'PENDING'], 201)]);
        $u = User::factory()->create();

        $this->actingAs($u)->post('/portefeuille/acheter', ['pack' => 'p50', 'operator' => 'orange', 'phone' => '0812345678'])->assertRedirect('/portefeuille');
        Http::assertSent(fn ($r) => $r['network'] === 'orange_cd' && $r['amount'] === '1000.00' && $r['customer']['phone'] === '+243812345678');
        $p = CoinPurchase::firstOrFail();
        $this->assertSame(['pending', 'tx_123', 'orange'], [$p->status, $p->provider_ref, $p->operator]);

        $body = json_encode(['event' => 'transaction.success', 'data' => ['id' => 'tx_123', 'status' => 'SUCCESS']]);
        $ts = (string) time();
        // Mauvaise signature : refusé, rien n'est crédité
        $this->call('POST', '/api/payments/webhook/saspay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => 'faux'], $body)->assertStatus(401);
        $this->assertSame(0, (int) $u->fresh()->coins);
        $this->call('POST', '/api/payments/webhook/saspay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'whsec')], $body)->assertOk();
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(50, (int) $u->fresh()->coins);
    }
}
