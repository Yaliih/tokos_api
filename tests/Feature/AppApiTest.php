<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** L'API de l'app mobile : mêmes données et mêmes règles que le site. */
class AppApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_endpoints(): void
    {
        $this->getJson('/api/app/config')->assertOk()->assertJsonPath('data.me', null)->assertJsonStructure(['data' => ['features', 'categories', 'tournament_sizes']]);
        $u = User::factory()->create(['interests' => ['rap']]);
        $token = $u->createToken('test')->plainTextToken;
        $h = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/app/config', $h)->assertJsonPath('data.me.handle', $u->username);

        $c = $this->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Qui rappe mieux ?', 'content' => 'Qui rappe mieux ?', 'category' => 'rap'], $h)->assertCreated()->json('data');
        $this->getJson('/api/app/home', $h)->assertOk()->assertJsonPath('data.0.verb', 'competition.created');
        $this->getJson('/api/app/home?vue=ouvertes', $h)->assertOk()->assertJsonPath('data.0.slug', $c['slug']);
        $this->getJson('/api/app/home?vue=direct', $h)->assertOk();

        $this->postJson('/api/app/competitions/'.$c['slug'].'/like', [], $h)->assertOk();
        $this->postJson('/api/app/competitions/'.$c['slug'].'/comments', ['content' => 'Trop fort'], $h)->assertOk();
        $this->getJson('/api/app/competitions/'.$c['slug'].'/comments')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.content', 'Trop fort');
        $this->postJson('/api/app/interests', ['interests' => ['danse', 'sciences']], $h)->assertOk();
        $this->assertSame(['danse', 'sciences'], $u->fresh()->interests);

        $this->getJson('/api/app/leaderboards', $h)->assertOk()->assertJsonStructure(['data' => ['boards', 'period']]);
        $other = User::factory()->create();
        $other->follow($u);
        $this->getJson('/api/app/users/'.$u->username.'/abonnes', $h)->assertOk()->assertJsonPath('data.0.handle', $other->username)->assertJsonPath('data.0.follows_me', true);
        $this->getJson('/api/app/wallet', $h)->assertOk()->assertJsonStructure(['data' => ['balance', 'history']]);

        // Profil, suivre, modifier son profil, règles de modification
        $this->getJson('/api/app/users/'.$other->username, $h)->assertOk()->assertJsonPath('data.follows_me', true)->assertJsonPath('data.is_me', false)
            ->assertJsonStructure(['data' => ['participant', 'stats', 'activities', 'followers', 'following']]);
        $this->postJson('/api/app/follow/'.$other->username, [], $h)->assertOk()->assertJsonPath('is_following', true)->assertJsonPath('followers', 1);
        $this->postJson('/api/app/follow/'.$other->username, [], $h)->assertOk()->assertJsonPath('is_following', false);
        $this->postJson('/api/app/profile', ['first_name' => 'Jo', 'username' => 'jo_kin', 'city' => 'goma', 'interests_sent' => 1, 'interests' => ['rap']], $h)
            ->assertOk()->assertJsonPath('data.username', 'jo_kin');
        $this->assertSame('Goma', $u->fresh()->city);
        $this->getJson('/api/app/competitions/'.$c['slug'].'/edit', $h)->assertOk()->assertJsonPath('data.can', true);

        // Espaces
        $space = \App\Models\Space::create(['name' => 'Crew Test', 'slug' => 'crew-test', 'owner_id' => $other->id, 'is_active' => true]);
        $this->getJson('/api/app/spaces')->assertOk()->assertJsonPath('data.0.handle', 'crew-test');
        $this->postJson('/api/app/spaces/crew-test/join', [], $h)->assertOk()->assertJsonPath('is_member', true);
        $this->getJson('/api/app/spaces/crew-test', $h)->assertOk()->assertJsonPath('data.is_member', true);
    }

    public function test_home_stories_list_live_duels(): void
    {
        $u = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($u);
        $this->postJson('/api/competitions', ['type' => 'duel', 'format' => 'versus', 'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2])->assertCreated();
        $r = $this->getJson('/api/app/stories')->assertOk();
        $this->assertSame('Pondu', $r->json('data.0.a.name'));
        $this->assertNotNull($r->json('data.0.ends_at'));
    }

    public function test_profile_saves_one_option_at_a_time_and_accepts_a_video_cover(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $u = \App\Models\User::factory()->create(['first_name' => 'Esther', 'username' => 'esther', 'bio' => 'Je chante.']);
        \Laravel\Sanctum\Sanctum::actingAs($u);

        // Seulement la bio : le reste ne bouge pas
        $r = $this->postJson('/api/app/profile', ['bio' => 'Je chante et je danse.'])->assertOk();
        $this->assertSame('Je chante et je danse.', $r->json('data.bio'));
        $this->assertSame('esther', $u->fresh()->username);
        $this->assertSame('Esther', $u->fresh()->first_name);

        // Seulement le pseudo, avec un message clair s'il est mauvais
        $this->postJson('/api/app/profile', ['username' => 'mauvais pseudo'])->assertStatus(422)->assertJsonPath('errors.username.0', 'Le pseudo : lettres, chiffres, point ou tiret bas, sans espace.');
        $this->postJson('/api/app/profile', ['username' => 'esther.voix'])->assertOk()->assertJsonPath('data.username', 'esther.voix');
        $this->assertFalse($u->fresh()->canChangeUsername());

        // Seulement la couverture, en vidéo
        $r = $this->post('/api/app/profile', ['cover' => \Illuminate\Http\UploadedFile::fake()->create('cover.mp4', 300, 'video/mp4')], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('video', $r->json('data.cover_type'));
        $this->assertSame('video', $u->fresh()->cover_type);
        $this->assertSame('Je chante et je danse.', $u->fresh()->bio);

        // Retirer la couverture
        $this->postJson('/api/app/profile', ['remove_cover' => true])->assertOk()->assertJsonPath('data.cover_url', null);
    }

    public function test_username_changes_once_and_the_old_one_stays_reserved_and_redirects(): void
    {
        $u = \App\Models\User::factory()->create(['username' => 'mbuyi']);
        $other = \App\Models\User::factory()->create(['username' => 'kabila']);
        \Laravel\Sanctum\Sanctum::actingAs($u);

        $this->getJson('/api/app/users/mbuyi')->assertOk()->assertJsonPath('data.can_change_username', true);
        // Envoyer le même pseudo ne compte pas comme un changement
        $this->postJson('/api/app/profile', ['username' => 'MBUYI', 'bio' => 'Salut'])->assertOk();
        $this->assertTrue($u->fresh()->canChangeUsername());

        // Le changement unique
        $this->postJson('/api/app/profile', ['username' => 'mbuyi.dance'])->assertOk()->assertJsonPath('data.can_change_username', false);
        // Plus de second changement
        $this->postJson('/api/app/profile', ['username' => 'mbuyi.encore'])->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'Tu as déjà changé ton pseudo une fois : il ne peut plus être modifié.');

        // L'ancien pseudo mène au profil actuel (site et app)
        $this->get('/@mbuyi')->assertRedirect('/@mbuyi.dance');
        $this->getJson('/api/app/users/mbuyi')->assertOk()->assertJsonPath('data.username', 'mbuyi.dance');

        // Personne d'autre ne peut le prendre, même en majuscules
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->postJson('/api/app/profile', ['username' => 'Mbuyi'])->assertStatus(422)->assertJsonPath('errors.username.0', 'Ce pseudo est déjà pris.');
        $this->assertSame('kabila', $other->fresh()->username);
        $this->assertTrue($other->fresh()->canChangeUsername());
    }

    public function test_feed_carries_people_and_spaces_suggestions_like_the_site(): void
    {
        $me = \App\Models\User::factory()->create();
        \App\Models\User::factory()->count(3)->create();
        \Laravel\Sanctum\Sanctum::actingAs($me);
        $r = $this->getJson('/api/app/home')->assertOk();
        $this->assertSame('people', $r->json('suggestions.0.kind'));
        $this->assertSame(2, $r->json('suggestions.0.at'));
        $this->assertNotEmpty($r->json('suggestions.0.items.0.handle'));
        // « Tout voir » : la liste complète des personnes à suivre
        $this->getJson('/api/app/suggestions')->assertOk()->assertJsonCount(3, 'data');
        $this->get('/a-suivre')->assertOk()->assertSee('À suivre');
    }

    public function test_people_search_and_global_search(): void
    {
        $me = \App\Models\User::factory()->create(['first_name' => 'Moi']);
        $esther = \App\Models\User::factory()->create(['first_name' => 'Esther', 'last_name' => 'Mwamba', 'username' => 'esther.voix']);
        \App\Models\User::factory()->create(['first_name' => 'Patrick', 'last_name' => 'Ilunga', 'username' => 'patrick']);
        $me->following()->attach($esther->id);
        \Laravel\Sanctum\Sanctum::actingAs($me);

        // « À suivre » avec une recherche : nom et prénom, ou pseudo ; on sait si on suit déjà
        $r = $this->getJson('/api/app/suggestions?q=esther mw')->assertOk();
        $this->assertSame('esther.voix', $r->json('data.0.handle'));
        $this->assertTrue($r->json('data.0.following'));
        $this->assertCount(1, $this->getJson('/api/app/suggestions?q=@patr')->json('data'));
        $this->assertCount(0, $this->getJson('/api/app/suggestions?q=personne')->json('data'));

        // La loupe : personnes, Espaces et publications
        $this->postJson('/api/competitions', ['type' => 'challenge', 'content' => 'Esther, qui chante mieux ?'])->assertCreated();
        $g = $this->getJson('/api/app/search?q=esther')->assertOk();
        $this->assertSame('esther.voix', $g->json('data.people.0.handle'));
        $this->assertNotEmpty($g->json('data.competitions'));

        $this->get('/a-suivre?q=patrick')->assertOk()->assertSee('@patrick');
    }
}
