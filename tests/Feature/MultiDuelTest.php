<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionInvitation;
use App\Models\User;
use App\Services\Competition\CompetitionService;
use App\Support\Presenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Défier plusieurs personnes d'un coup : chacune reçoit son propre duel. Et le temps des concours et tournois. */
class MultiDuelTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_duel_per_opponent(): void
    {
        [$me, $a, $b] = User::factory()->count(3)->create();
        Sanctum::actingAs($me);
        $res = $this->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'challenge', 'content' => 'Ma punchline',
            'opponents' => ['@'.$a->username, '@'.$b->username, '@inconnu_total', '0812345678'],
        ])->assertCreated();
        $this->assertSame(3, $res->json('count')); // @a, @b, et le numéro (invitation par SMS)
        $this->assertCount(1, $res->json('failed'));
        $this->assertSame(3, Competition::where('type', 'duel')->count());
        $this->assertSame(1, CompetitionInvitation::where('invitee_id', $a->id)->count());
        $this->assertSame(1, CompetitionInvitation::where('invitee_id', $b->id)->count());
        $this->assertSame(1, \App\Models\CompetitionPhoneInvite::count());

        // Le site : les étiquettes « opponents[] »
        $this->actingAs($me)->post('/creer', ['type' => 'duel', 'format' => 'challenge', 'content' => 'Encore', 'opponents' => ['@'.$a->username, '@'.$b->username]])->assertRedirect();
        $this->assertSame(5, Competition::where('type', 'duel')->count());

        // Un seul adversaire : comme avant
        $this->postJson('/api/competitions', ['type' => 'duel', 'format' => 'challenge', 'content' => 'Seul', 'opponent' => $a->username])->assertCreated()->assertJsonPath('count', 1);
        $this->assertSame(11, count(CompetitionService::duelTargets(['opponents' => array_map(fn ($i) => '@u'.$i, range(1, 11))])));
        $this->postJson('/api/competitions', ['type' => 'duel', 'format' => 'challenge', 'content' => 'Trop', 'opponents' => array_map(fn ($i) => '@u'.$i, range(1, 11))])->assertStatus(422);
    }

    public function test_contests_and_tournaments_always_show_their_time(): void
    {
        $u = User::factory()->create();
        $open = Competition::forceCreate(['type' => 'contest', 'status' => 'open', 'title' => 'C', 'slug' => 'c1', 'created_by' => $u->id, 'organizer_type' => User::class, 'organizer_id' => $u->id]);
        $this->assertSame('Inscriptions ouvertes', Presenter::timing($open)['label']);
        $open->forceFill(['registration_ends_at' => now()->addDay()])->save();
        $this->assertSame(['left', 'Inscriptions'], [Presenter::timing($open)['state'], Presenter::timing($open)['what']]);
        $open->forceFill(['status' => 'live', 'ends_at' => now()->addHours(5)])->save();
        $this->assertSame('Votes', Presenter::timing($open->fresh())['what']);
        $open->forceFill(['type' => 'tournament', 'ends_at' => null])->save();
        $this->assertSame('Tournoi en cours', Presenter::timing($open->fresh())['label']);
    }
}
