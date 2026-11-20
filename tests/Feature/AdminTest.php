<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageFeatures;
use App\Filament\Resources\Certifications\CertificationResource;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Models\VerificationCode;
use App\Models\VerificationRequest;
use App\Services\CertificationService;
use App\Services\Messaging\Messenger;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Administration : accès, interrupteurs de fonctions, certification, envoi des messages, mot de passe oublié. */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_enter_the_panel(): void
    {
        [$user, $admin] = User::factory()->count(2)->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->get('/admin')->assertRedirect();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/fonctions')->assertOk()->assertSee('Tournois');
        $this->actingAs($admin)->get('/admin/regles')->assertOk()->assertSee('Vidéo de couverture');
    }

    public function test_switched_off_features_disappear(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Livewire::test(ManageFeatures::class)
            ->set('data.swipe', false)
            ->set('data.tournaments', false)
            ->set('data.registration', false)
            ->set('data.coins', false)
            ->call('save')
            ->assertHasNoErrors();
        Features::flush();

        $this->assertFalse(Features::on('swipe'));
        $this->assertFalse(Features::on('gifts')); // éteint avec les coins
        $this->get('/voter')->assertNotFound();
        $this->get('/portefeuille')->assertNotFound();
        $this->get('/creer')->assertOk()->assertDontSee('value="tournament"', false);
        $this->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'T', 'description' => 'x'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->get('/inscription')->assertNotFound();
        $this->get('/connexion')->assertOk()->assertDontSee('Créer un compte');
    }

    public function test_certification_request_and_approval(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['avatar' => 'avatars/a.jpg']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get('/parametres/certification')->assertOk()->assertSee(CertificationService::selfieCode($user));
        $this->actingAs($user)->post('/parametres/certification', [
            'category' => 'student', 'full_name' => 'Grâce Mbuyi', 'document_type' => 'student_card',
            'document' => UploadedFile::fake()->image('piece.jpg'), 'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertSessionHasErrors('proof'); // étudiant : justificatif obligatoire

        $this->actingAs($user)->post('/parametres/certification', [
            'category' => 'student', 'full_name' => 'Grâce Mbuyi', 'document_type' => 'student_card',
            'document' => UploadedFile::fake()->image('piece.jpg'), 'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'proof' => UploadedFile::fake()->image('attestation.jpg'), 'links' => 'https://instagram.com/grace',
        ])->assertRedirect();
        $request = VerificationRequest::firstOrFail();
        Storage::disk('local')->assertExists($request->document_path);

        $this->actingAs($admin);
        Livewire::test(\App\Filament\Resources\Certifications\Pages\ListCertifications::class)
            ->assertCanSeeTableRecords([$request])
            ->callTableAction('approve', $request);
        $this->assertNotNull($user->fresh()->verified_at);
        $this->assertSame('approved', $request->fresh()->status);

        // Pièces supprimées après le délai
        $request->update(['reviewed_at' => now()->subDays(40)]);
        $this->assertSame(1, app(CertificationService::class)->purge());
        $this->assertNull($request->fresh()->document_path);
    }

    public function test_messages_fall_back_to_the_next_channel(): void
    {
        config(['messaging.drivers.whatsapp' => 'meta', 'messaging.meta.token' => null]);
        $messenger = app(Messenger::class);
        $messenger->saveChannels(['whatsapp', 'log']);
        $this->assertTrue($messenger->sendCode('+243810000001', '123456'));
        $message = OutboundMessage::firstOrFail();
        $this->assertSame('log', $message->channel);
        $this->assertFalse($message->attempts[0]['ok']);
        $this->assertStringNotContainsString('123456', $message->body); // le code n'est pas gardé en clair
    }

    public function test_password_reset_by_code(): void
    {
        $user = User::factory()->create(['phone_code' => '+243', 'phone_number' => '812345678']);
        $this->post('/mot-de-passe-oublie', ['phone_code' => '+243', 'phone_number' => '0812 345 678'])->assertRedirect('/mot-de-passe-oublie');
        $code = VerificationCode::where('phone_number', '812345678')->latest('id')->value('code');
        $this->assertNotNull($code);
        $this->post('/mot-de-passe-oublie/nouveau', ['verification_code' => '000000', 'password' => 'nouveau1', 'password_confirmation' => 'nouveau1'])
            ->assertSessionHasErrors('verification_code');
        $this->post('/mot-de-passe-oublie/nouveau', ['verification_code' => $code, 'password' => 'nouveau1', 'password_confirmation' => 'nouveau1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nouveau1', $user->fresh()->password));
    }

    public function test_visits_are_tracked_and_stats_page_loads(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->get('/')->assertOk();
        $this->actingAs($user)->get('/decouvrir')->assertOk();
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('user_visits')->where('user_id', $user->id)->count());
        $this->assertNotNull($user->fresh()->last_seen_at);
        $this->actingAs($admin)->get('/admin/statistiques')->assertOk()->assertSee('Statistiques');
    }

    /** Le bloc « Visiteurs sans compte » : des personnes, pas des rechargements, et le choix de période. */
    public function test_guest_block_counts_people_and_views(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $now = now();

        // 2 visiteurs sans compte (6 vues à eux deux) + 1 membre (1 vue)
        $vues = [
            ['g:aaa', null, 1, 0], ['g:aaa', null, 2, 0], ['g:aaa', null, 3, 0],
            ['g:bbb', null, 1, 0], ['g:bbb', null, 2, 0], ['g:bbb', null, 3, 0],
            ['u:'.$admin->id, $admin->id, 1, 0],
            ['g:aaa', null, 4, 20], // trop ancienne pour 7 jours, comptée dans « Tout »
        ];
        foreach ($vues as [$cle, $userId, $contenu, $jours]) {
            \Illuminate\Support\Facades\DB::table('content_views')->insert([
                'viewable_type' => \App\Models\Competition::class,
                'viewable_id' => $contenu,
                'viewer_key' => $cle,
                'user_id' => $userId,
                'created_at' => $now->copy()->subDays($jours),
            ]);
        }

        // « Tout » : 8 vues, 7 sans compte, 2 visiteurs différents, 1 membre
        $this->actingAs($admin)->get('/admin/statistiques?periode=tout')
            ->assertOk()
            ->assertSee('Visiteurs sans compte')
            ->assertSee('personnes différentes')
            ->assertSee('7 vues sans compte')
            ->assertSee('88 %')
            ->assertSee('7 vues sur 8')
            ->assertSee('personne avec un compte')
            ->assertSee('depuis le '.$now->copy()->subDays(20)->locale('fr')->translatedFormat('j M Y'));

        // « 7 jours » ne garde que les vues récentes : 7 vues, 6 sans compte
        $this->actingAs($admin)->get('/admin/statistiques?periode=7j')
            ->assertOk()
            ->assertSee('6 vues sur 7')
            ->assertDontSee('7 vues sur 8');
    }

    /** Le tri de période change vraiment les chiffres, sans recharger la page. */
    public function test_guest_block_period_buttons_recalculate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        \Illuminate\Support\Facades\DB::table('content_views')->insert([
            'viewable_type' => \App\Models\Competition::class, 'viewable_id' => 1,
            'viewer_key' => 'g:zzz', 'user_id' => null, 'created_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($admin);
        Livewire::test(\App\Filament\Widgets\Stats\Guests::class)
            ->assertSee('personne différente')
            ->assertSee('1 vue sans compte')
            ->assertSee('1 vue sur 1')
            ->call('choisirPeriode', 'tout')
            ->assertSee('1 vue sur 1')
            ->call('choisirPeriode', '24h')
            ->assertSee('1 vue sur 1');
    }

    public function test_photos_are_shrunk_on_upload(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $file = UploadedFile::fake()->image('grande.jpg', 4000, 3000)->size(900);
        $media = app(\App\Services\MediaUploader::class)->storeOne(['file' => $file], 'test');
        $this->assertSame('image', $media['type']);
        $this->assertSame(1600, $media['metadata']['width']);
        $this->assertStringEndsWith('.jpg', $media['url']);
    }
}
