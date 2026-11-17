<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le site web s'affiche, en visiteur comme connecté, et les actions principales marchent. */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_pages_render_for_guests_and_members(): void
    {
        $this->seed(DemoSeeder::class);
        $c = Competition::where('type', 'tournament')->first();
        $m = CompetitionMatch::where('status', 'voting')->first();
        $u = User::first();

        foreach (['/', '/decouvrir', '/decouvrir?type=challenge', '/voter', '/classements', '/espaces', '/connexion', '/inscription',
            $c->url(), $c->url().'?tab=tableau', $c->url().'?tab=participants', '/m/'.$m->id, '/@'.$u->username] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/creer')->assertRedirect();

        $this->actingAs($u);
        foreach (['/', '/creer', '/notifications', '/defis-recus', $c->url().'?tab=gestion', $c->url().'?tab=propositions'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** Toutes les pages, pour chaque profil, Espace, compétition et duel de la démo, en visiteur et connecté. */
    public function test_every_page_renders_with_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $urls = ['/', '/?vue=direct', '/?vue=ouvertes', '/fil', '/decouvrir', '/decouvrir?type=contest', '/decouvrir?statut=termines', '/voter', '/classements', '/classements?kind=space', '/espaces'];
        foreach (User::all() as $u) {
            $urls[] = '/@'.$u->username;
        }
        foreach (\App\Models\Space::all() as $s) {
            $urls[] = '/espaces/'.$s->slug;
        }
        foreach (Competition::all() as $c) {
            foreach (['', '?tab=duels', '?tab=tableau', '?tab=classement', '?tab=participants', '?tab=propositions', '?tab=gestion', '?tab=commentaires', '?tab=infos'] as $tab) {
                $urls[] = $c->url().$tab;
            }
        }
        foreach (CompetitionMatch::all() as $m) {
            $urls[] = '/m/'.$m->id;
        }

        foreach ([null, User::first(), User::orderByDesc('id')->first()] as $viewer) {
            $viewer ? $this->actingAs($viewer) : auth()->guard('web')->logout();
            foreach ($urls as $url) {
                $res = $this->get($url);
                $this->assertContains($res->getStatusCode(), [200, 302], 'Page en erreur : '.$url.($viewer ? ' (connecté)' : ' (visiteur)'));
            }
        }
        $this->assertTrue(true);
    }

    public function test_web_create_and_vote_flow(): void
    {
        [$a, $b, $voter] = User::factory()->count(3)->create();

        $this->actingAs($a)->post('/creer', [
            'type' => 'challenge', 'title' => 'Mon défi web', 'content' => 'À vous !', 'match_duration_hours' => 24,
            'participation_mode' => 'individual', 'access' => 'open', 'media_types' => ['text', 'image'],
        ])->assertRedirect();
        $c = Competition::where('title', 'Mon défi web')->firstOrFail();

        $this->actingAs($b)->post($c->url().'/participer', ['content' => 'Je relève'])->assertRedirect($c->url());
        $m = $c->matches()->first();
        $this->actingAs($voter)->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_b_id])
            ->assertOk()->assertJsonStructure(['html', 'message']);
        $this->assertSame(1, $m->fresh()->votes_b);
    }

    /** Aperçus de partage, signalement, mode swipe. */
    public function test_share_images_reports_and_swipe(): void
    {
        $this->seed(DemoSeeder::class);
        $m = CompetitionMatch::where('status', 'voting')->first();
        $c = Competition::first();
        $this->get('/m/'.$m->id.'/apercu.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/c/'.$c->slug.'/apercu.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/m/'.$m->id)->assertSee('/m/'.$m->id.'/apercu.png', false);

        $u = User::first();
        $this->actingAs($u)->postJson('/signaler', ['type' => 'match', 'id' => $m->id, 'reason' => 'spam'])->assertOk();
        $this->actingAs($u)->postJson('/signaler', ['type' => 'match', 'id' => $m->id, 'reason' => 'cheating'])->assertOk();
        $this->assertSame(1, \App\Models\ContentReport::count());
        $this->actingAs($u)->postJson('/signaler', ['type' => 'match', 'id' => $m->id])->assertStatus(422);
        $this->get('/voter')->assertOk()->assertSee('data-swipe', false);
        $this->get('/competitions?type=duel')->assertRedirect('/decouvrir?type=duel');
    }

    /** Modifier son profil et son Espace : photo, couverture, nom, bio. */
    public function test_edit_profile_and_space(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $u = User::factory()->create(['username' => 'avant']);
        $this->actingAs($u)->get('/parametres/profil')->assertOk()->assertSee('Modifier le profil');
        $this->actingAs($u)->post('/parametres/profil', [
            'first_name' => 'Grace', 'last_name' => 'Mbuyi', 'username' => 'grace_kin', 'bio' => 'Slam et rumba',
            'avatar' => \Illuminate\Http\UploadedFile::fake()->image('a.jpg', 300, 300),
            'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg', 1200, 400),
        ])->assertRedirect('/@grace_kin');
        $u->refresh();
        $this->assertSame('Slam et rumba', $u->bio);
        $this->assertSame('image', $u->cover_type);
        $this->assertNotNull($u->getRawOriginal('avatar'));
        $this->actingAs($u)->get('/parametres/profil')->assertOk(); // avec une couverture déjà en place
        // Pseudo déjà pris : refusé
        $other = User::factory()->create(['username' => 'pris']);
        $this->actingAs($u)->post('/parametres/profil', ['first_name' => 'Grace', 'username' => 'pris'])->assertSessionHasErrors('username');

        $space = \App\Models\Space::create(['owner_id' => $u->id, 'name' => 'Les Lions', 'slug' => 'les-lions']);
        $this->actingAs($u)->get('/espaces/les-lions/modifier')->assertOk();
        $this->actingAs($u)->post('/espaces/les-lions/modifier', ['name' => 'Les Lions de Kin', 'bio' => 'On gagne'])->assertRedirect('/espaces/les-lions');
        $this->assertSame('Les Lions de Kin', $space->fresh()->name);
        $this->actingAs($other)->get('/espaces/les-lions/modifier')->assertForbidden();
    }
}
