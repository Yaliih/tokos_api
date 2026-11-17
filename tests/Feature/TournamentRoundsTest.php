<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\User;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tournoi : à chaque tour une nouvelle prestation, avec un délai fixé par la plateforme. Sans envoi, l'autre passe. */
class TournamentRoundsTest extends TestCase
{
    use RefreshDatabase;

    private function round2(): array
    {
        $org = User::factory()->create();
        $c = app(CompetitionService::class)->create($org, ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Coupe', 'max_entries' => 4, 'match_duration_hours' => 2]);
        $players = User::factory()->count(4)->create();
        foreach ($players as $p) {
            app(CompetitionService::class)->submitEntry($p, $c->fresh(), ['content' => 'Prestation 1']);
        }
        $c->refresh();
        if ($c->status !== 'live') {
            app(CompetitionService::class)->startNow($c);
        }
        // Premier tour : la prestation de l'inscription, les votes tout de suite
        $this->assertSame(2, $c->matches()->where('round', 1)->where('status', 'voting')->count());
        foreach ($c->matches()->where('round', 1)->get() as $m) {
            app(MatchService::class)->close($m);
        }
        $final = $c->matches()->where('round', 2)->with(['entryA.participant', 'entryB.participant'])->firstOrFail();

        return [$c->fresh(), $final];
    }

    public function test_next_round_waits_for_both_new_performances_within_platform_deadline(): void
    {
        [$c, $m] = $this->round2();
        $this->assertSame('preparing', $m->status);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $m->submit_by->timestamp, 5);

        $this->actingAs($m->entryA->participant)->get($m->url())->assertOk()->assertSee('Envoie ton texte pour ce tour', false); // format du tournoi : celui du premier inscrit (texte)
        $this->actingAs(User::factory()->create())->post('/m/'.$m->id.'/prestation', ['content' => 'x'])->assertRedirect(); // pas participant : refusé
        $this->assertNull($m->fresh()->a_submitted_at);

        app(MatchService::class)->submitRound($m->entryA->participant, $m, ['content' => 'Finale A']);
        $this->assertSame('preparing', $m->fresh()->status);
        app(MatchService::class)->submitRound($m->entryB->participant, $m->fresh(), ['content' => 'Finale B']);
        $m->refresh();
        $this->assertSame('voting', $m->status);
        $this->assertNotNull($m->ends_at);
        $this->actingAs(User::factory()->create())->get($m->url())->assertSee('Finale A')->assertSee('Finale B');
    }

    public function test_who_does_not_submit_in_time_loses(): void
    {
        [$c, $m] = $this->round2();
        app(MatchService::class)->submitRound($m->entryB->participant, $m, ['content' => 'Seul B']);
        $this->travel(25)->hours();
        $this->artisan('competitions:tick');
        $m->refresh();
        $this->assertSame('completed', $m->status);
        $this->assertSame('a', $m->forfeit);
        $this->assertSame($m->entry_b_id, $m->winner_entry_id);
        $this->assertSame('completed', $c->fresh()->status);   // c'était la finale
        $this->assertSame($m->entry_b_id, $c->fresh()->winner_entry_id);
    }

    public function test_nobody_submits_the_better_seed_goes_through(): void
    {
        [$c, $m] = $this->round2();
        $this->travel(25)->hours();
        app(MatchService::class)->resolvePreparation($m);
        $m->refresh();
        $this->assertSame('both', $m->forfeit);
        $better = ($m->entryA->seed ?? 99) <= ($m->entryB->seed ?? 99) ? $m->entry_a_id : $m->entry_b_id;
        $this->assertSame($better, $m->winner_entry_id);
    }
}
