<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\Messenger;
use App\Support\NotifyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** SMS par eMess (Infobip en relais), changement de mot de passe par code, réglages des notifications. */
class MessagingNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        NotifyRules::flush();
        Cache::forget('emess.token');
        parent::tearDown();
    }

    private function smsConfig(): void
    {
        config([
            'messaging.channels' => ['sms'], 'messaging.drivers.sms' => 'emess,infobip',
            'messaging.emess' => ['url' => 'https://emess.test/api/v1', 'app_id' => 'app', 'secret_key' => 'sec',
                'code_template' => 'auth-otp',
                'code_text' => '[{app_name}] Votre code de vérification est {code}. Il expire dans {validity_minutes} min. Ne le partagez pas.'],
            'messaging.infobip' => ['base_url' => 'ib.test', 'api_key' => 'k', 'sender' => 'Sosa'],
        ]);
        app(Messenger::class)->saveChannels(['sms']);
    }

    public function test_code_goes_by_emess_template_then_infobip_when_emess_fails(): void
    {
        $this->smsConfig();
        Http::fake([
            'emess.test/api/v1/auth/token' => Http::response(['token' => 'tok']),
            'emess.test/api/v1/sms/send' => Http::response(['id' => 'em1']),
        ]);
        $this->assertTrue(app(Messenger::class)->sendCode('+243812345678', '123456'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sms/send') && $r['template'] === 'auth-otp' && $r['params']['code'] === '123456' && $r['params']['app_name'] === 'Sosa' && $r['params']['validity_minutes'] === '10');
    }

    public function test_infobip_takes_over_when_emess_fails(): void
    {
        $this->smsConfig();
        Http::fake([
            'emess.test/*' => Http::response(['message' => 'crédit'], 402),
            'ib.test/*' => Http::response(['messages' => [['messageId' => 'ib1', 'status' => ['groupName' => 'PENDING']]]]),
        ]);
        $this->assertTrue(app(Messenger::class)->send('+243812345678', 'Bonjour', 'text'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ib.test/sms/2/text/advanced') && $r['messages'][0]['destinations'][0]['to'] === '243812345678');
    }

    public function test_plain_fallback_uses_the_approved_template_text(): void
    {
        $this->smsConfig();
        Http::fake([
            'emess.test/api/v1/auth/token' => Http::response(['token' => 'tok']),
            'emess.test/api/v1/sms/send' => Http::sequence()
                ->push(['message' => 'modele refuse'], 422)
                ->push(['id' => 'em2']),
        ]);
        $this->assertTrue(app(Messenger::class)->sendCode('+243812345678', '123456'));
        // Le repli en texte brut doit etre le TEXTE EXACT du modele approuve
        Http::assertSent(fn ($r) => isset($r['message'])
            && str_starts_with($r['message'], '[Sosa] Votre code de vérification est 123456.')
            && str_contains($r['message'], '10 min.'));
    }
    public function test_change_password_with_sms_code(): void
    {
        config(['messaging.channels' => ['log']]);
        app(Messenger::class)->saveChannels(['log']);
        $me = User::factory()->create(['phone_code' => '+243', 'phone_number' => '812345678', 'password' => Hash::make('ancien1')]);
        Sanctum::actingAs($me);
        $this->postJson('/api/app/password/code')->assertOk()->assertJsonPath('sent_to', '+243 •••• 678');
        $code = \App\Models\VerificationCode::latest('id')->value('code');
        $this->postJson('/api/app/password', ['verification_code' => '000000', 'password' => 'nouveau1', 'password_confirmation' => 'nouveau1'])->assertStatus(422);
        $this->postJson('/api/app/password', ['verification_code' => $code, 'password' => 'nouveau1', 'password_confirmation' => 'nouveau1'])->assertOk();
        $this->assertTrue(Hash::check('nouveau1', $me->fresh()->password));

        $this->actingAs($me)->get('/parametres/mot-de-passe')->assertOk()->assertSee('Recevoir le code par SMS');
    }

    public function test_admin_rules_decide_bell_and_push(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $svc = app(\App\Services\Social\NotificationService::class);
        NotifyRules::save(['follower.new' => ['app' => false, 'push' => false], 'comment.new' => ['app' => true, 'push' => false]]);
        $svc->send($b->id, 'follower.new', 'Nouvel abonné', 'x', '/');
        $svc->send($b->id, 'comment.new', 'Commentaire', 'y', '/');
        $types = $b->fresh()->notifications()->pluck('data')->map(fn ($d) => $d['type'])->all();
        $this->assertSame(['comment.new'], $types);
        $this->assertArrayNotHasKey('_push', $b->fresh()->notifications()->first()->data);
        $this->assertFalse(NotifyRules::allows('comment.new', 'push'));

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/notifications-reglages')->assertOk()->assertSee('Nouvel abonné');
        $this->actingAs($admin)->get('/admin/messages-envoi')->assertOk();
    }
}
