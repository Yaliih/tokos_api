<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Rating;
use App\Models\User;
use App\Services\Social\ActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le fil privilégie les gens suivis, les pages abonnés/abonnements, le classement repensé. */
class FollowFeedRankTest extends TestCase
{
    use RefreshDatabase;

    private function act(User $u): Activity
    {
        return Activity::create(['actor_type' => User::class, 'actor_id' => $u->id, 'verb' => 'post.created', 'visibility' => 'public', 'importance' => 2, 'data' => []]);
    }

    public function test_feed_puts_followed_people_first_and_keeps_some_discovery(): void
    {
        [$me, $friend, $stranger] = User::factory()->count(3)->create();
        $me->follow($friend);
        foreach (range(1, 6) as $i) {
            $this->act($friend);
        }
        foreach (range(1, 12) as $i) {
            $this->act($stranger); // plus récents que ceux de l'ami
        }
        $svc = app(ActivityService::class);
        $page = $svc->feedFor($me, null, 8);
        $actors = $page['items']->pluck('actor_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($friend->id, $actors[0]);                       // l'ami d'abord, même moins récent
        $this->assertGreaterThanOrEqual(5, count(array_keys($actors, $friend->id)));
        $this->assertContains($stranger->id, $actors);                    // un peu de découverte
        $this->assertTrue($page['items']->firstWhere('actor_id', $stranger->id)->is_suggested);

        // Pages suivantes : on finit par tout voir, sans doublon
        $seen = $page['items']->pluck('id')->all();
        $cursor = $page['next_cursor'];
        while ($cursor) {
            $p = $svc->feedFor($me, $cursor, 8);
            $seen = array_merge($seen, $p['items']->pluck('id')->all());
            $cursor = $p['next_cursor'];
        }
        $this->assertCount(18, $seen);
        $this->assertCount(18, array_unique($seen));
        $this->get('/fil?avant='.urlencode(ActivityService::encode(null, null)))->assertOk();
    }

    public function test_followers_and_following_pages(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();
        $b->follow($a);
        $c->follow($a);
        $a->follow($b);
        $this->actingAs($a)->get('/@'.$a->username)->assertSee('/@'.$a->username.'/abonnes', false);
        $this->get('/@'.$a->username.'/abonnes')->assertOk()->assertSee($b->first_name)->assertSee($c->first_name)->assertSee('Suivre en retour');
        $html = $this->get('/@'.$a->username.'/abonnements')->assertOk()->getContent();
        $list = substr($html, strpos($html, 'follows-list'), strpos($html, '</ul>', strpos($html, 'follows-list')) - strpos($html, 'follows-list'));
        $this->assertStringContainsString($b->username, $list);
        $this->assertStringNotContainsString($c->username, $list);
    }

    public function test_leaderboard_shows_my_place_friends_and_top10_not_a_huge_list(): void
    {
        $week = Rating::currentWeek();
        $users = User::factory()->count(30)->create(['city' => 'Kinshasa']);
        foreach ($users as $i => $u) {
            Rating::create(['rateable_type' => User::class, 'rateable_id' => $u->id, 'category' => 'all', 'season' => $week, 'points' => 100 - $i, 'wins' => 1, 'matches' => 1]);
        }
        $me = $users[20];
        $me->follow($users[25]);
        $html = $this->actingAs($me)->get('/classements')->assertOk()
            ->assertSee('21<sup>e</sup>', false)->assertSee('Toi et tes abonnements')->assertSee('À Kinshasa')->assertSee('Top 10 de Sosa')
            ->assertSee($users[19]->first_name)->assertSee($users[22]->first_name) // mes voisins
            ->getContent();
        $this->assertStringNotContainsString($users[28]->first_name.' '.$users[28]->last_name, $html); // pas toute la liste
        $this->get('/classements?vue=tout')->assertOk()->assertSee($users[28]->first_name);
        $this->get('/classements?periode=saison')->assertOk()->assertSee('Pas encore de points cette saison');
    }

    public function test_challenge_duels_sheet_from_feed(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/creer', ['type' => 'challenge', 'content' => 'Qui danse mieux que moi ?'])->assertRedirect();
        $c = \App\Models\Competition::latest('id')->firstOrFail();
        $this->get('/')->assertSee('data-duels="'.$c->slug.'"', false)->assertSee('<div class="who-meta">Défi</div>', false);
        $html = $this->getJson('/c/'.$c->slug.'/duels-apercu')->assertOk()->json('html');
        $this->assertStringContainsString('Relever le défi', $html);
        $this->assertStringContainsString('Pas encore de duel', $html);
        $this->actingAs(User::factory()->create());
        $this->assertStringContainsString($c->url().'#participer', $this->getJson('/c/'.$c->slug.'/duels-apercu')->json('html'));
    }
}
