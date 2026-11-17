<?php

namespace Tests\Feature;

use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Classement : revoir les saisons passées (site et app). */
class PastSeasonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_season_can_be_viewed(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        [$old, $new] = User::factory()->count(2)->create();
        Rating::create(['rateable_type' => User::class, 'rateable_id' => $old->id, 'category' => 'all', 'season' => '2026-T3', 'points' => 40, 'wins' => 4]);
        Rating::create(['rateable_type' => User::class, 'rateable_id' => $new->id, 'category' => 'all', 'season' => '2026-T4', 'points' => 10, 'wins' => 1]);

        $this->assertSame(['2026-T4', '2026-T3'], array_column(Rating::seasons(), 'value'));
        $this->assertSame('Saison juil.–sept. 2026', Rating::seasonLabel('2026-T3'));

        $this->getJson('/api/app/leaderboards?periode=saison&vue=tout&saison=2026-T3')->assertOk()
            ->assertJsonPath('data.season', '2026-T3')->assertJsonPath('data.past', true)
            ->assertJsonPath('data.rows.0.participant.id', $old->id)->assertJsonCount(1, 'data.rows')->assertJsonCount(2, 'data.seasons');
        // Saison inconnue : on revient à la saison en cours
        $this->getJson('/api/app/leaderboards?periode=saison&saison=1999-T1')->assertJsonPath('data.season', '2026-T4')->assertJsonPath('data.past', false);

        $this->get('/classements?periode=saison&saison=2026-T3')->assertOk()->assertSee('Saison terminée')->assertSee('juil.–sept.');
    }
}
