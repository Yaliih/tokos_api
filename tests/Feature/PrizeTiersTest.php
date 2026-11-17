<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use App\Services\Coins\CoinService;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\PrizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Récompenses par place, coins bloqués puis versés ; tailles de tournoi ; l'organisateur ne concourt pas. */
class PrizeTiersTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(User $u, array $extra = []): Competition
    {
        return app(CompetitionService::class)->create($u, array_merge(['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Coupe de slam', 'max_entries' => 8], $extra));
    }

    public function test_tournament_size_must_be_a_power_of_two_and_within_limits(): void
    {
        $u = User::factory()->create();
        $this->assertSame(8, $this->tournament($u)->max_entries);
        $this->expectExceptionMessage('4, 8, 16, 32');
        $this->tournament($u, ['max_entries' => 10]);
    }

    public function test_user_tournament_is_limited_to_32(): void
    {
        $this->expectExceptionMessage('jusqu\'à 32 participants');
        $this->tournament(User::factory()->create(), ['max_entries' => 64]);
    }

    public function test_coins_must_be_owned_then_are_locked_paid_and_rest_refunded(): void
    {
        $u = User::factory()->create();
        $tiers = [['from' => 1, 'to' => 1, 'text' => '100 $', 'coins' => 500], ['from' => 2, 'to' => 2, 'coins' => 200], ['from' => 3, 'to' => 4, 'coins' => 50]];
        try {
            $this->tournament($u, ['prize_tiers' => $tiers]);
            $this->fail('Il fallait refuser : pas assez de coins.');
        } catch (\App\Exceptions\CompetitionException $e) {
            $this->assertStringContainsString('800 coins', $e->getMessage());
        }

        app(CoinService::class)->credit($u, 1000, 'bonus');
        $c = $this->tournament($u->fresh(), ['prize_tiers' => $tiers]);
        $this->assertSame(800, $c->prize_escrow);
        $this->assertSame(200, $u->fresh()->coins);
        $this->assertStringContainsString('1er : 100 $ + 500 coins', $c->prize);

        // Classement final : 1er, 2e, deux 3es ex æquo (demi-finalistes) ; personne d'autre
        [$a, $b, $s1, $s2] = User::factory()->count(4)->create();
        foreach ([[$a, 1, 'winner'], [$b, 2, 'eliminated'], [$s1, 3, 'eliminated'], [$s2, 3, 'eliminated']] as [$p, $rank, $st]) {
            $c->entries()->create(['participant_type' => User::class, 'participant_id' => $p->id, 'submitted_by' => $p->id, 'status' => $st, 'rank' => $rank]);
        }
        $this->assertSame(800, app(PrizeService::class)->payout($c));
        $this->assertSame(500, $a->fresh()->coins);
        $this->assertSame(200, $b->fresh()->coins);
        $this->assertSame(50, $s1->fresh()->coins);   // les places 3 et 4 partagées
        $this->assertSame(50, $s2->fresh()->coins);
        $this->assertSame(0, app(PrizeService::class)->payout($c->fresh())); // jamais deux fois
    }

    public function test_unawarded_places_and_cancel_give_coins_back(): void
    {
        $u = User::factory()->create();
        app(CoinService::class)->credit($u, 300, 'bonus');
        $c = $this->tournament($u->fresh(), ['prize_tiers' => [['from' => 1, 'to' => 1, 'coins' => 100], ['from' => 2, 'to' => 2, 'coins' => 100]]]);
        $this->assertSame(100, $u->fresh()->coins);
        app(CompetitionService::class)->cancel($u, $c);
        $this->assertSame(300, $u->fresh()->coins);
    }

    public function test_overlapping_places_are_refused(): void
    {
        $this->expectExceptionMessage('se chevaucher');
        $this->tournament(User::factory()->create(), ['prize_tiers' => [['from' => 1, 'to' => 3, 'text' => 'A'], ['from' => 2, 'to' => 2, 'text' => 'B']]]);
    }

    public function test_organizer_is_not_an_entrant(): void
    {
        $u = User::factory()->create();
        $c = $this->tournament($u);
        $this->assertSame(0, $c->entries()->count());
        $this->expectExceptionMessage('organisateur ne peut pas être candidat');
        app(CompetitionService::class)->addCandidate($u, $c, ['label' => 'Moi', 'username' => $u->username]);
    }

    public function test_finished_or_closed_competitions_show_no_join_button(): void
    {
        $org = User::factory()->create();
        $t = $this->tournament($org);
        $t->update(['registration_ends_at' => now()->subHour()]);
        $html = $this->actingAs(User::factory()->create())->get($t->url())->assertOk()->assertSee('Les inscriptions sont closes')->getContent();
        $this->assertStringNotContainsString('data-open="participer"', $html);
        $this->get('/?vue=ouvertes')->assertOk()->assertDontSee($t->title);
        $this->assertSame('Inscriptions closes', $t->fresh()->statusLabel());

        $this->actingAs($org)->post('/creer', ['type' => 'challenge', 'content' => 'Qui chante mieux que moi ?'])->assertRedirect();
        $ch = Competition::latest('id')->first();
        $ch->update(['ends_at' => now()->subMinute()]);
        $this->actingAs(User::factory()->create());
        $this->get($ch->url())->assertSee('est déjà terminé')->assertDontSee('data-open="participer"', false);
        $this->assertStringContainsString('déjà terminé', $this->getJson('/c/'.$ch->slug.'/duels-apercu')->json('html'));
        $this->get('/')->assertDontSee('data-duels-tab="join"', false);
        $t->update(['status' => 'completed']);
        $this->get($t->url())->assertSee('est déjà terminé');
    }

    public function test_feed_shows_contest_standings_and_tournament_bracket_cards(): void
    {
        $org = User::factory()->create();
        $c = $this->tournament($org);
        $c->forceFill(['type' => 'contest', 'status' => 'live', 'starts_at' => now()->subDay(), 'title' => 'Miss Kin'])->save();
        foreach (User::factory()->count(12)->create() as $i => $u) {
            $c->entries()->create(['participant_type' => User::class, 'participant_id' => $u->id, 'submitted_by' => $u->id, 'status' => 'active', 'score' => 100 - $i, 'votes_count' => 100 - $i]);
        }
        $c->forceFill(['entries_count' => 12])->save();
        $this->assertSame(1, \App\Support\FeedCards::postStandings());
        $this->assertSame(0, \App\Support\FeedCards::postStandings()); // pas deux fois le même jour

        $t = $this->tournament($org, ['title' => 'Coupe de rap']);
        $pairs = array_fill(0, 8, ['https://x.test/a.png', null]);
        app(\App\Services\Social\ActivityService::class)->record($org, 'tournament.round', $t, null,
            ['round' => 1, 'round_name' => '8es de finale', 'remaining' => 16, 'bracket' => ['round' => 1, 'rounds' => 4, 'pairs' => $pairs], 'competition' => $t->summary()], 3);

        $html = $this->actingAs($org)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Classement provisoire · 12 candidats', $html);
        $this->assertStringContainsString('fs-gap', $html);           // une tranche plus loin
        $this->assertStringContainsString('class="fb-svg"', $html);   // le tableau
        $this->assertStringContainsString('8es de finale', $html);
        $this->assertStringContainsString('16 en lice', $html);
    }
}
