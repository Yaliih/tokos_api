<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Publier un défi ou un duel en un écran, et le badge des nouveaux duels. */
class QuickPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenge_and_duel_use_the_one_screen_composer(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get('/creer')->assertOk()->assertSee('data-quick', false)->assertSee('Ton défi, en une phrase')
            ->assertDontSee('Montrer chaque duel dans le fil');
        $this->get('/creer?type=duel')->assertOk()->assertSee('Qui veux-tu défier')->assertSee('Deux choix');
        $this->get('/creer?type=contest')->assertOk()->assertSee('data-wizard', false);
    }

    public function test_challenge_needs_only_a_text(): void
    {
        $u = User::factory()->create(['first_name' => 'Grace']);
        $this->actingAs($u)->post('/creer', ['type' => 'challenge', 'content' => "Qui rappe mieux que moi en 30 secondes ?\nVenez !", 'category' => 'rap'])
            ->assertRedirect();
        $c = Competition::firstOrFail();
        $this->assertSame('challenge', $c->type);
        $this->assertSame('Qui rappe mieux que moi en 30 secondes ?', $c->title);
        $this->assertTrue((bool) ($c->settings['show_duels_in_feed'] ?? false));
    }

    public function test_first_line_title_and_limits(): void
    {
        $u = User::factory()->create();
        // Titre seul (une ligne puis Entrée, rien d'autre) : il sert aussi de texte du défi
        $this->actingAs($u)->post('/creer', ['type' => 'challenge', 'title' => 'Le meilleur slam sur Kin'])->assertRedirect();
        $c = Competition::latest('id')->firstOrFail();
        $this->assertSame('Le meilleur slam sur Kin', $c->title);
        $this->assertSame(['text'], $c->media_types);
        // Titre + détails
        $this->post('/creer', ['type' => 'challenge', 'title' => 'Freestyle 30 s', 'content' => 'Sur une instru afro, une seule prise.'])->assertRedirect();
        $this->assertSame('Freestyle 30 s', Competition::latest('id')->value('title'));
        $this->get('/creer')->assertSee('maxlength="80"', false)->assertSee('maxlength="1000"', false);
    }

    public function test_duel_badge_counts_only_new_duels(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/competitions', ['type' => 'duel', 'format' => 'versus', 'title' => 'Pondu ou moambe ?',
            'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2])->assertCreated();
        $this->assertSame(1, CompetitionMatch::where('status', 'voting')->count());

        $this->actingAs($me)->get('/')->assertSee('<span class="n">1</span>', false);
        $this->get('/?vue=direct')->assertOk();
        $this->get('/')->assertDontSee('<span class="n">', false);   // déjà vus
        $this->travel(1)->minutes();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/competitions', ['type' => 'duel', 'format' => 'versus', 'title' => 'Rumba ou ndombolo ?',
            'side_a_label' => 'Rumba', 'side_b_label' => 'Ndombolo', 'match_duration_hours' => 2])->assertCreated();
        $this->actingAs($me)->get('/')->assertSee('<span class="n">1</span>', false);
    }

    public function test_organizer_cannot_enter_his_own_contest_and_comments_open_in_a_sheet(): void
    {
        $org = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($org);
        $slug = $this->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Concours de slam', 'description' => 'Un slam'])->assertCreated()->json('data.slug');
        $c = Competition::where('slug', $slug)->firstOrFail();

        // L'organisateur ne voit pas « Participer » et ne peut pas s'inscrire
        $this->actingAs($org)->get('/c/'.$slug)->assertOk()->assertDontSee('data-open="participer"', false)->assertSee('Tu organises');
        $this->post('/c/'.$slug.'/participer', ['content' => 'Moi aussi'])->assertSessionHas('error');
        $this->assertSame(0, $c->entries()->count());
        // Les autres oui, avec le bouton principal visible
        $this->actingAs($other)->get('/c/'.$slug)->assertOk()->assertSee('data-open="participer"', false)->assertSee('cta-bar', false);

        // Commentaires : chargés dans la fenêtre, publiés sans quitter la page
        $this->postJson('/c/'.$slug.'/commenter', ['content' => 'Bravo !'])->assertOk()->assertJson(['success' => true]);
        $this->getJson('/c/'.$slug.'/commentaires')->assertOk()->assertJson(['count' => 1])->assertJsonPath('html', fn ($h) => str_contains($h, 'Bravo !'));
        // L'ancien lien ?tab=commentaires affiche la publication (la fenêtre s'ouvre par-dessus)
        $this->get('/c/'.$slug.'?tab=commentaires')->assertOk()->assertSee('Concours de slam');
    }

    public function test_registration_asks_one_thing_per_screen(): void
    {
        $this->get('/inscription')->assertOk()->assertSee('Comment tu t\'appelles ?', false)->assertSee('Ta date de naissance')
            ->assertSee('Choisis un mot de passe')->assertSee('data-step="5"', false);
    }
}
