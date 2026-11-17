<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Page « Compétitions » d'un profil : organisées, participées (filtres et tris), stats. */
class ParticipationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_competitions_tabs(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $entry = CompetitionEntry::where('participant_type', User::class)->firstOrFail();
        $user = User::findOrFail($entry->participant_id);
        $c = $entry->competition;
        $c->forceFill(['status' => Competition::STATUS_COMPLETED, 'winner_entry_id' => $entry->id])->save();
        $base = '/api/app/profile-competitions/user/'.$user->username;

        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/app/users/'.$user->username)->json('data.stats.competitions_count'));
        foreach (['recent', 'best', 'votes', 'oldest'] as $sort) {
            $r = $this->getJson($base.'?tab=joined&status=done&sort='.$sort)->assertOk();
            $this->assertContains($c->id, array_column($r->json('data'), 'id'), $sort);
        }
        $won = collect($this->getJson($base.'?tab=joined&sort=best')->json('data'))->firstWhere('id', $c->id);
        $this->assertContains($won['result']['label'], ['Gagné', '1er']);
        foreach (['recent', 'oldest', 'entries', 'votes', 'ending'] as $sort) {
            $this->getJson($base.'?tab=organized&status=live&sort='.$sort)->assertOk()->assertJsonStructure(['data', 'sorts' => ['organized', 'joined', 'statuses']]);
        }
        $st = $this->getJson($base.'?tab=stats')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $st['joined']);
        $this->assertGreaterThanOrEqual(1, $st['won']);
        $this->assertCount(4, $st['by_type']);
    }
}
