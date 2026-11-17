<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Badge;
use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\Rating;
use App\Models\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompetitionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function space(User $owner, string $name = 'Les Lions'): Space
    {
        $space = Space::create(['owner_id' => $owner->id, 'name' => $name, 'is_active' => true]);
        $space->addManager($owner, 'admin');

        return $space;
    }

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function tick(int $hours = 25): void
    {
        $this->travel($hours)->hours();
        $this->artisan('competitions:tick')->assertSuccessful();
    }

    public function test_challenge_full_cycle_with_votes_and_anti_cheat(): void
    {
        [$owner, $c1, $c2, $voter, $voter2] = User::factory()->count(5)->create();

        $res = $this->as($owner)->postJson('/api/competitions', [
            'type' => 'challenge', 'title' => 'Freestyle 30 secondes', 'category' => 'rap',
            'content' => 'Mon couplet, à vous !', 'duration_hours' => 24,
        ])->assertCreated();
        $slug = $res->json('data.slug');
        $this->assertSame('open', $res->json('data.status'));
        $this->assertNotNull($res->json('data.post_id'));

        // Le créateur ne peut pas relever son propre défi
        $this->as($owner)->postJson("/api/competitions/$slug/entries", ['content' => 'moi'])->assertStatus(422);

        // Deux challengers → deux duels
        $this->as($c1)->postJson("/api/competitions/$slug/entries", ['content' => 'Réponse 1'])->assertCreated();
        $this->as($c2)->postJson("/api/competitions/$slug/entries", ['content' => 'Réponse 2'])->assertCreated();
        $this->as($c1)->postJson("/api/competitions/$slug/entries", ['content' => 'encore'])->assertStatus(422);
        $this->assertSame(2, CompetitionMatch::count());

        $match = CompetitionMatch::orderBy('id')->first();
        $ownerEntry = $match->entry_a_id;
        $c1Entry = $match->entry_b_id;

        // Un participant ne vote pas dans son duel
        $this->as($c1)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $c1Entry])->assertStatus(403);

        // Vote, changement, retrait, nouveau vote
        $this->as($voter)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $ownerEntry])->assertOk()->assertJson(['action' => 'added']);
        $this->as($voter)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $c1Entry])->assertOk()->assertJson(['action' => 'switched']);
        $m = $match->fresh();
        $this->assertSame([0, 1], [$m->votes_a, $m->votes_b]);
        $this->as($voter)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $c1Entry])->assertOk()->assertJson(['action' => 'removed']);
        $this->as($voter)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $c1Entry])->assertOk();
        $this->as($voter2)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $c1Entry])->assertOk();
        $this->assertSame(2, $match->fresh()->votes_b);

        // Lecture publique (sans connexion) du duel
        auth()->forgetGuards();
        $this->getJson("/api/matches/{$match->id}")->assertOk()->assertJsonPath('data.viewer.can_vote', false);

        // Fin des votes : le challenger gagne
        $this->tick(25);
        $m = $match->fresh();
        $this->assertSame('completed', $m->status);
        $this->assertSame($c1Entry, $m->winner_entry_id);
        $this->assertGreaterThan(1000, Rating::where(['rateable_id' => $c1->id, 'rateable_type' => User::class, 'category' => 'all', 'season' => 'all'])->value('rating'));
        $this->assertTrue(Badge::where('owner_id', $c1->id)->where('code', 'first_win')->exists());
        $this->assertTrue(Activity::where('verb', 'match.completed')->exists());
        $this->assertGreaterThan(0, $c1->notifications()->count());

        // Revanche demandée par le perdant
        $this->as($owner)->postJson("/api/matches/{$match->id}/rematch")->assertCreated()->assertJsonPath('data.type', 'duel');
        $this->assertSame(1, $c1->fresh()->notifications()->where('type', 'challenge.received')->count());

        // Défi terminé après la date de fin (7 jours par défaut)
        $this->tick(24 * 7);
        $this->assertSame('completed', Competition::where('slug', $slug)->value('status'));
    }

    public function test_versus_duel_and_feed(): void
    {
        [$creator, $v1, $v2, $v3] = User::factory()->count(4)->create();
        $v1->follow($creator);

        $res = $this->as($creator)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Meilleur plat ?', 'category' => 'cuisine',
            'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2,
        ])->assertCreated();
        $this->assertSame('live', $res->json('data.status'));
        $match = CompetitionMatch::first();

        foreach ([$v1, $v2] as $u) {
            $this->as($u)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $match->entry_b_id])->assertOk();
        }
        $this->as($v3)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $match->entry_a_id])->assertOk();

        $this->tick(3);
        $this->assertSame($match->entry_b_id, $match->fresh()->winner_entry_id);
        $this->assertSame('completed', Competition::first()->status);

        // Le fil de v1 (qui suit le créateur) montre la création et le résultat
        $feed = $this->as($v1)->getJson('/api/activity')->assertOk();
        $verbs = collect($feed->json('data'))->pluck('verb');
        $this->assertContains('competition.created', $verbs);
        $this->assertContains('match.completed', $verbs);
        $text = collect($feed->json('data'))->firstWhere('verb', 'match.completed')['text'];
        $this->assertStringContainsString('Moambe a battu Pondu', $text);
    }

    public function test_two_choice_duel_keeps_its_description_for_the_feed(): void
    {
        $creator = User::factory()->create();
        $this->as($creator)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Pondu ou Moambe ?', 'content' => "Le plat du dimanche.\nOn tranche une bonne fois.",
            'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2,
        ])->assertCreated();
        $c = Competition::first();
        $this->assertSame('versus', $c->format);
        $this->assertSame("Le plat du dimanche.\nOn tranche une bonne fois.", $c->description);
        $this->assertSame(2, $c->entries()->count()); // les deux choix, pas de performance du créateur

        $data = \App\Support\Presenter::competition($c->fresh(), $creator);
        $this->assertSame($c->description, $data['blurb']);
        $this->assertSame($c->description, $data['featured_match']['competition']['blurb']);
    }

    public function test_two_choice_duel_from_the_app_with_a_photo_on_each_side(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $creator = User::factory()->create();
        $this->as($creator)->post('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Lequel ?', 'description' => 'Choisis ton préféré.',
            'side_a_label' => 'Rouge', 'side_b_label' => 'Bleu', 'duration_hours' => 24,
            'side_a_media' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('a.jpg', 600, 600), 'type' => 'image']],
            'side_b_media' => [['file' => \Illuminate\Http\UploadedFile::fake()->image('b.jpg', 600, 600), 'type' => 'image']],
        ], ['Accept' => 'application/json'])->assertCreated();
        $c = Competition::first();
        $this->assertSame('Choisis ton préféré.', $c->description);
        $this->assertSame(['image'], $c->media_types);
        $this->assertSame(2, $c->entries()->whereNotNull('media')->count());
    }

    public function test_participant_limits_depend_on_who_publishes(): void
    {
        $u = User::factory()->create();
        $space = $this->space($u);

        // En son nom : 50 challengers au plus ; depuis un Espace : bien plus
        $r = $this->as($u)->postJson('/api/competitions', ['type' => 'challenge', 'content' => 'Qui chante mieux ?', 'max_entries' => 100]);
        $r->assertStatus(422);
        $this->assertStringContainsString('Publie depuis un de tes Espaces', $r->json('message'));
        $this->as($u)->postJson('/api/competitions', ['type' => 'challenge', 'content' => 'Qui chante mieux ?', 'max_entries' => 100, 'space' => $space->slug])->assertCreated();

        // Tournoi : 64 refusé en son nom, accepté pour l'Espace
        $this->as($u)->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Grand tournoi', 'max_entries' => 64])->assertStatus(422);
        $this->as($u)->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Grand tournoi', 'max_entries' => 64, 'space' => $space->slug])->assertCreated();
    }

    public function test_contest_rules_that_must_fit_together(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $u = User::factory()->create();
        $base = ['type' => 'contest', 'title' => 'Plus belle voix', 'description' => 'Chante !', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg', 800, 450)];

        $r = $this->as($u)->post('/api/competitions', $base + ['max_entries' => 3, 'winners_count' => 3], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('plus de candidats que de lauréats', $r->json('message'));

        $r = $this->as($u)->post('/api/competitions', $base + ['max_entries' => 10, 'min_entries' => 20], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('ne peut pas dépasser', $r->json('message'));

        $r = $this->as($u)->post('/api/competitions', $base + ['registration_ends_at' => now()->addDays(7)->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String()], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('après la fin des inscriptions', $r->json('message'));
    }

    public function test_inbox_collects_challenges_and_candidates_waiting_for_me(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $owner = User::factory()->create();
        $me = User::factory()->create();
        $cand = User::factory()->create();

        // Un duel qu'on me lance
        $this->as($owner)->postJson('/api/competitions', ['type' => 'duel', 'format' => 'challenge', 'opponent' => $me->username, 'content' => 'Prêt ?', 'duration_hours' => 6])->assertCreated();
        // Un concours que j'organise, avec validation des candidatures
        $slug = $this->as($me)->post('/api/competitions', [
            'type' => 'contest', 'title' => 'Plus belle voix', 'description' => 'Chante', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'),
            'requires_approval' => true,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.slug');
        $this->as($cand)->post("/api/competitions/$slug/entries", ['content' => 'Je chante depuis dix ans', 'photo' => \Illuminate\Http\UploadedFile::fake()->image('moi.jpg')], ['Accept' => 'application/json'])->assertCreated();

        $r = $this->as($me)->getJson('/api/app/inbox')->assertOk()->assertJsonPath('count', 2);
        $kinds = collect($r->json('data'))->pluck('kind')->sort()->values()->all();
        $this->assertSame(['candidate', 'challenge'], $kinds);
        $this->as($me)->getJson('/api/app/home')->assertJsonPath('inbox', 2);

        // Accepter la candidature, refuser le duel : plus rien n'attend
        $entry = collect($r->json('data'))->firstWhere('kind', 'candidate');
        $this->as($me)->postJson('/api/app/inbox/candidatures/'.$entry['id'].'/accepter')->assertOk()->assertJsonPath('count', 1);
        $inv = collect($r->json('data'))->firstWhere('kind', 'challenge');
        $this->as($me)->postJson('/api/app/inbox/invitations/'.$inv['id'].'/refuser')->assertOk()->assertJsonPath('count', 0);

        // La page du site
        $this->as($me)->get('/defis-recus')->assertOk()->assertSee('Rien ne t\'attend pour le moment', false);
    }

    public function test_targeted_duel_between_spaces_with_member_vote_policy(): void
    {
        [$ownerA, $ownerB, $memberA, $neutral] = User::factory()->count(4)->create();
        $lions = $this->space($ownerA, 'Les Lions');
        $aigles = $this->space($ownerB, 'Les Aigles');
        $lions->addMember($memberA);

        $res = $this->as($ownerA)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'challenge', 'title' => 'Battle de danse', 'category' => 'danse',
            'space' => $lions->slug, 'opponent_type' => 'space', 'opponent' => $aigles->slug,
            'content' => 'Notre chorégraphie', 'member_vote_policy' => 'excluded',
        ])->assertCreated();
        $slug = $res->json('data.slug');
        $this->assertSame('space', $res->json('data.participation_mode'));

        // L'Espace adverse voit l'invitation et l'accepte
        $inv = $this->as($ownerB)->getJson('/api/invitations')->assertOk()->json('data.0');
        $this->assertSame('Les Aigles', $inv['invitee']['name']);
        $this->as($ownerB)->postJson("/api/invitations/{$inv['id']}/accept", ['content' => 'Notre réponse'])->assertOk();

        $c = Competition::where('slug', $slug)->first();
        $this->assertSame('live', $c->status);
        $match = $c->matches()->first();

        // Membre d'un Espace en compétition : vote refusé (vote neutre)
        $this->as($memberA)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $match->entry_a_id])->assertStatus(403);
        $this->as($neutral)->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $match->entry_b_id])->assertOk();

        $this->tick(25);
        $this->assertSame($match->entry_b_id, $match->fresh()->winner_entry_id);
        $this->assertTrue(Rating::where('rateable_type', Space::class)->where('rateable_id', $aigles->id)->where('wins', 1)->exists());
        $this->getJson('/api/leaderboards?kind=space')->assertOk()->assertJsonPath('data.rows.0.participant.name', 'Les Aigles');
    }

    public function test_space_only_competition_rejects_individuals_and_supports_proposals(): void
    {
        [$owner, $mgr, $member, $solo] = User::factory()->count(4)->create();
        $org = $this->space($owner, 'Ligue Kin');
        $team = $this->space($mgr, 'Team Gombe');
        $team->addMember($member);

        $slug = $this->as($owner)->postJson('/api/competitions', [
            'type' => 'contest', 'title' => 'Meilleure fresque', 'space' => $org->slug, 'description' => 'Une fresque par Espace', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'),
            'participation_mode' => 'space', 'media_types' => ['image'],
        ])->assertCreated()->json('data.slug');

        $this->as($solo)->postJson("/api/competitions/$slug/entries", ['content' => 'moi'])->assertStatus(422);

        // Un membre propose, le gestionnaire choisit
        Storage::fake('public');
        $this->as($member)->post("/api/competitions/$slug/proposals", [
            'space' => $team->slug,
            'media' => [['file' => UploadedFile::fake()->image('fresque.jpg'), 'type' => 'image']],
        ], ['Accept' => 'application/json'])->assertCreated();
        $proposal = $this->as($mgr)->getJson("/api/competitions/$slug/proposals")->assertOk()->json('data.0');
        $this->assertTrue($proposal['can_select']);
        $this->as($mgr)->postJson("/api/competitions/$slug/proposals/{$proposal['id']}/select")->assertOk()
            ->assertJsonPath('data.participant.name', 'Team Gombe')
            ->assertJsonPath('data.submitted_by.id', $member->id);
    }

    public function test_tournament_with_byes_runs_to_a_champion(): void
    {
        $users = User::factory()->count(5)->create();
        $org = User::factory()->create();
        $voter = User::factory()->create();

        $slug = $this->as($org)->postJson('/api/competitions', [
            'type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Coupe du rire', 'category' => 'humour', 'match_duration_hours' => 12,
        ])->assertCreated()->json('data.slug');

        foreach ($users as $u) {
            $this->as($u)->postJson("/api/competitions/$slug/entries", ['content' => 'Ma blague'])->assertCreated();
        }
        $this->as($org)->postJson("/api/competitions/$slug/start")->assertOk();

        $c = Competition::where('slug', $slug)->first();
        $this->assertSame(8, $c->settings['bracket_size']);
        $this->assertSame(3, $c->totalRounds());
        $this->assertSame(3, $c->matches()->where('is_bye', true)->count());
        $this->assertSame(1, $c->matches()->where('status', 'voting')->count());

        // On joue chaque tour : le premier côté gagne toujours
        for ($round = 1; $round <= 3; $round++) {
            // À partir du 2e tour, chacun envoie une nouvelle prestation, puis les votes s'ouvrent
            foreach ($c->matches()->where('status', 'preparing')->with(['entryA.participant', 'entryB.participant'])->get() as $m) {
                $this->as($m->entryA->participant)->postJson("/api/matches/{$m->id}/submission", ['content' => 'Nouvelle blague'])->assertOk();
                $this->as($m->entryB->participant)->postJson("/api/matches/{$m->id}/submission", ['content' => 'Autre blague'])->assertOk()->assertJsonPath('data.status', 'voting');
            }
            foreach ($c->matches()->where('status', 'voting')->get() as $m) {
                $this->as($voter)->postJson("/api/matches/{$m->id}/vote", ['entry_id' => $m->entry_a_id])->assertOk();
            }
            $this->tick(13);
        }

        $c->refresh();
        $this->assertSame('completed', $c->status);
        $this->assertNotNull($c->winner_entry_id);
        $champion = $c->winnerEntry->participant;
        $this->assertTrue(Badge::where('owner_id', $champion->id)->where('code', 'champion')->exists());
        $this->assertSame(1, $c->winnerEntry->rank);

        $bracket = $this->getJson("/api/competitions/$slug/bracket")->assertOk()->json('data.rounds');
        $this->assertSame(['quarts de finale', 'demi-finales', 'finale'], array_column($bracket, 'name'));
        $this->assertTrue(Activity::where('verb', 'competition.completed')->exists());
    }

    public function test_contest_with_multiple_votes_jury_and_ranking(): void
    {
        [$owner, $a, $b, $c, $juror] = User::factory()->count(5)->create();
        $voters = User::factory()->count(3)->create();
        $space = $this->space($owner, 'Miss Kin');

        // Jury aussi dans un tournoi, avec son pourcentage
        $t = $this->as($owner)->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Tournoi test', 'space' => $space->slug, 'jury_weight' => 40, 'jurors' => [$juror->username]])->assertCreated()->json('data');
        $this->assertSame(40, Competition::where('slug', $t['slug'])->value('jury_weight'));
        $this->assertTrue(Competition::where('slug', $t['slug'])->first()->isJuror($juror));

        // Un compte personnel ne peut pas utiliser de jury
        $this->as($owner)->postJson('/api/competitions', ['type' => 'contest', 'title' => 'X', 'jury_weight' => 30])->assertStatus(422);

        $slug = $this->as($owner)->postJson('/api/competitions', [
            'type' => 'contest', 'title' => 'Plus belle voix', 'category' => 'chant', 'space' => $space->slug,
            'description' => 'Chante 30 secondes', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            'votes_per_user' => 2, 'winners_count' => 1, 'jury_weight' => 50, 'jurors' => [$juror->username],
            'requires_approval' => true,
        ])->assertCreated()->json('data.slug');

        foreach ([$a, $b, $c] as $u) {
            // Candidature : sans photo ni présentation, refusée
            $this->as($u)->postJson("/api/competitions/$slug/entries", ['content' => 'Ma voix'])->assertStatus(422);
            $this->as($u)->post("/api/competitions/$slug/entries", [
                'content' => 'Je chante depuis dix ans', 'photo' => \Illuminate\Http\UploadedFile::fake()->image('moi.jpg'),
            ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending');
        }
        $pending = $this->as($owner)->getJson("/api/competitions/$slug/entries?status=pending")->json('data');
        $this->assertCount(3, $pending);
        foreach ($pending as $p) {
            $this->as($owner)->postJson("/api/competitions/$slug/entries/{$p['id']}/approve")->assertOk();
        }
        $entries = collect($pending)->keyBy(fn ($p) => $p['participant']['id']);

        $this->as($owner)->postJson("/api/competitions/$slug/start")->assertOk();

        // 2 votes par personne maximum
        $v = $voters[0];
        $this->as($v)->postJson("/api/competitions/$slug/entries/{$entries[$a->id]['id']}/vote")->assertOk();
        $this->as($v)->postJson("/api/competitions/$slug/entries/{$entries[$b->id]['id']}/vote")->assertOk()->assertJsonPath('votes_left', 0);
        $this->as($v)->postJson("/api/competitions/$slug/entries/{$entries[$c->id]['id']}/vote")->assertStatus(422);
        // On ne vote pas pour soi
        $this->as($a)->postJson("/api/competitions/$slug/entries/{$entries[$a->id]['id']}/vote")->assertStatus(403);
        foreach ([$voters[1], $voters[2]] as $u) {
            $this->as($u)->postJson("/api/competitions/$slug/entries/{$entries[$a->id]['id']}/vote")->assertOk();
        }

        // Le jury préfère C
        $this->as($juror)->postJson("/api/competitions/$slug/entries/{$entries[$c->id]['id']}/jury-score", ['score' => 10])->assertOk();
        $this->as($juror)->postJson("/api/competitions/$slug/entries/{$entries[$a->id]['id']}/jury-score", ['score' => 6])->assertOk();

        $this->tick(30);
        $comp = Competition::where('slug', $slug)->first();
        $this->assertSame('completed', $comp->status);
        // A : public 100 + jury 60 → 80 ; C : public 0 + jury 100 → 50 ; B : public 33 + 0 → 16,7
        $this->assertSame($a->id, $comp->winnerEntry->participant_id);
        $ranking = $this->getJson("/api/competitions/$slug/entries?sort=ranking")->json('data');
        $this->assertSame([$a->id, $c->id, $b->id], array_column(array_column($ranking, 'participant'), 'id'));
        $this->assertTrue(Badge::where('owner_id', $a->id)->where('code', 'laureate')->exists());
    }

    public function test_discover_stats_and_notifications_endpoints(): void
    {
        [$u, $v] = User::factory()->count(2)->create();
        $this->as($u)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Dessin rapide', 'content' => 'go'])->assertCreated();
        $this->as($v)->postJson('/api/follow/'.$u->username)->assertOk();

        $this->getJson('/api/discover')->assertOk()->assertJsonStructure(['data' => ['hot_matches', 'open_challenges', 'top_spaces', 'season']]);
        $this->getJson('/api/competitions?type=challenge')->assertOk()->assertJsonPath('data.0.title', 'Dessin rapide');
        $this->getJson('/api/competitions/options')->assertOk();
        $this->getJson('/api/stats/users/'.$u->username)->assertOk()->assertJsonPath('data.rating', 1000);

        $this->as($u)->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.notifications', 1);
        $n = $this->as($u)->getJson('/api/notifications')->assertOk()->json('data.0');
        $this->assertSame('follower.new', $n['type']);
        $this->as($u)->postJson('/api/notifications/read')->assertOk();
        $this->as($u)->getJson('/api/notifications/unread-count')->assertJsonPath('data.notifications', 0);

        // Fil : "v suit maintenant u"
        $this->assertStringContainsString('suit maintenant', collect($this->as($u)->getJson('/api/activity')->json('data'))->firstWhere('verb', 'user.followed')['text']);
    }

    public function test_post_feed_includes_competition_cards_and_tabs(): void
    {
        [$u, $v] = User::factory()->count(2)->create();
        $this->as($u)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Défi du fil', 'content' => 'go'])->assertCreated();
        $this->as($u)->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Tournoi du fil'])->assertCreated();

        $all = $this->as($v)->getJson('/api/posts/feed')->assertOk()->json('data');
        $this->assertSame('competition', $all[0]['type']);
        $this->assertArrayHasKey('featured_match', $all[0]['competition']);

        $duels = $this->as($v)->getJson('/api/posts/feed?type=duels')->json('data');
        $this->assertSame(['Tournoi du fil'], array_column(array_column($duels, 'competition'), 'title'));
        $challenges = $this->as($v)->getJson('/api/posts/feed?type=challenges')->json('data');
        $this->assertSame(['Défi du fil'], array_column(array_column($challenges, 'competition'), 'title'));
    }

    public function test_media_route_blocks_path_traversal(): void
    {
        $this->get('/api/get-media/..&..&..&,env')->assertNotFound();
        $this->get('/api/get-media/..&..&composer,json')->assertNotFound();
    }

    public function test_duel_rules_phone_invites_and_shared_challenge_end(): void
    {
        [$owner, $c1, $c2] = User::factory()->count(3)->create();

        // Duel lancé directement : pas de titre à saisir, message par défaut
        $duel = $this->as($owner)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'challenge', 'opponent' => $c1->username, 'content' => 'Prêt ?', 'duration_hours' => 6,
        ])->assertCreated()->json('data');
        $this->assertStringContainsString('contre', $duel['title']);
        $this->assertNotEmpty(\App\Models\CompetitionInvitation::where('competition_id', $duel['id'])->value('message'));

        // Adversaire inconnu sans numéro : refusé avec une explication
        $this->as($owner)->postJson('/api/competitions', ['type' => 'duel', 'format' => 'challenge', 'opponent' => 'personne_ici', 'content' => 'x'])
            ->assertStatus(422);

        // Adversaire sans compte : invitation par numéro, retrouvée à l'inscription
        $this->as($owner)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'challenge', 'opponent_phone' => '0812345678', 'opponent_phone_code' => '+243', 'content' => 'Viens',
        ])->assertCreated();
        $this->assertSame(1, \App\Models\CompetitionPhoneInvite::where('phone_number', '812345678')->where('status', 'pending')->count());
        $newcomer = User::factory()->create(['phone_number' => '812345678']);
        $this->assertSame('claimed', \App\Models\CompetitionPhoneInvite::where('phone_number', '812345678')->value('status'));
        $this->assertTrue(\App\Models\CompetitionInvitation::where('invitee_type', User::class)->where('invitee_id', $newcomer->id)->exists());

        // Sur invitation : la liste des invités est obligatoire
        $this->as($owner)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Privé', 'content' => 'x', 'access' => 'invited'])
            ->assertStatus(422);

        // Défi : tous les duels se terminent à la fin du défi
        $slug = $this->as($owner)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Défi commun', 'content' => 'go', 'duration_hours' => 48])
            ->assertCreated()->json('data.slug');
        $this->as($c1)->postJson("/api/competitions/$slug/entries", ['content' => 'un'])->assertCreated();
        $this->travel(10)->hours();
        $this->as($c2)->postJson("/api/competitions/$slug/entries", ['content' => 'deux'])->assertCreated();
        $c = Competition::where('slug', $slug)->first();
        $ends = CompetitionMatch::where('competition_id', $c->id)->pluck('ends_at')->map->timestamp->unique();
        $this->assertCount(1, $ends);
        $this->assertSame($c->ends_at->timestamp, $ends->first());
        $this->travelBack();
    }

    public function test_media_duration_limit_on_server(): void
    {
        $u = User::factory()->create();
        config(['competition.media.max_audio_seconds' => 5]);
        // Durée annoncée par le client (le fichier factice n'est pas lisible)
        $this->as($u)->post('/api/competitions', [
            'type' => 'challenge', 'title' => 'Audio long', 'content' => 'x',
            'media' => [['file' => UploadedFile::fake()->create('voix.mp3', 100, 'audio/mpeg'), 'type' => 'audio', 'metadata' => '{"duration":42}']],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonFragment(['Audio trop longue : 5 s maximum.']);
    }

    public function test_views_are_unique_per_person(): void
    {
        [$owner, $a, $b] = User::factory()->count(3)->create();
        $slug = $this->as($owner)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Vues uniques', 'content' => 'go'])->json('data.slug');
        auth()->forgetGuards();

        // Même personne, plusieurs ouvertures (site et app) : une seule vue
        $this->actingAs($a)->get("/c/$slug")->assertOk();
        $this->actingAs($a)->get("/c/$slug")->assertOk();
        Sanctum::actingAs($a);
        $this->getJson("/api/competitions/$slug")->assertOk();
        $this->assertSame(1, Competition::where('slug', $slug)->value('views_count'));

        // Une autre personne : deuxième vue
        $this->actingAs($b)->get("/c/$slug")->assertOk();
        $this->assertSame(2, Competition::where('slug', $slug)->value('views_count'));

        // Visiteur sans compte : une vue, gardée par son cookie
        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $first = $this->get("/c/$slug")->assertOk();
        $cookie = collect($first->headers->getCookies())->first(fn ($c) => $c->getName() === \App\Support\Views::COOKIE);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie(\App\Support\Views::COOKIE, $cookie->getValue())->get("/c/$slug")->assertOk();
        $this->assertSame(3, Competition::where('slug', $slug)->value('views_count'));
    }

    public function test_same_format_rule_and_feed_shows_everything(): void
    {
        [$owner, $c1, $stranger] = User::factory()->count(3)->create();

        // Défi en texte : un challenger ne peut pas répondre en vidéo
        $slug = $this->as($owner)->postJson('/api/competitions', [
            'type' => 'challenge', 'title' => 'Punchline du jour', 'description' => 'La meilleure punchline gagne',
            'content' => 'Je suis plus rapide que le wifi du quartier', 'entry_format' => 'text',
        ])->assertCreated()->json('data.slug');
        $this->assertSame('text', Competition::where('slug', $slug)->first()->entryFormat());
        $this->as($c1)->post("/api/competitions/$slug/entries", [
            'media' => [['file' => UploadedFile::fake()->create('v.mp4', 100, 'video/mp4'), 'type' => 'video']],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->as($c1)->postJson("/api/competitions/$slug/entries", ['content' => 'Moi je charge plus vite que ton téléphone'])->assertCreated();

        // Défi en vidéo (déduit de la performance du créateur) : un texte seul est refusé
        $vid = $this->as($owner)->post('/api/competitions', [
            'type' => 'challenge', 'title' => 'Danse 15 s',
            'media' => [['file' => UploadedFile::fake()->create('d.mp4', 100, 'video/mp4'), 'type' => 'video']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.slug');
        $this->assertSame('video', Competition::where('slug', $vid)->first()->entryFormat());
        $this->as($c1)->postJson("/api/competitions/$vid/entries", ['content' => 'juste du texte'])->assertStatus(422);

        // Le fil d'une personne qui ne suit personne montre quand même les défis publiés
        auth()->forgetGuards();
        $this->actingAs($stranger)->get('/')->assertOk()->assertSee('Punchline du jour')->assertSee('Danse 15 s');
    }

    public function test_file_type_comes_from_content_not_from_client(): void
    {
        [$owner, $c1] = User::factory()->count(2)->create();
        $slug = $this->as($owner)->post('/api/competitions', [
            'type' => 'challenge', 'title' => 'Défi photo', 'description' => 'La plus belle photo de Kin',
            'media' => [['file' => UploadedFile::fake()->image('kin.jpg'), 'type' => 'image']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.slug');

        // Une vidéo annoncée comme photo est refusée
        $this->as($c1)->post("/api/competitions/$slug/entries", [
            'media' => [['file' => UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4'), 'type' => 'image']],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        // Un chemin de fichier déjà stocké n'est pas accepté à la place d'un envoi
        $this->as($c1)->postJson("/api/competitions/$slug/entries", ['media' => [['url' => 'demo/m1.jpg', 'type' => 'image']]])->assertStatus(422);
        // Une vraie photo passe
        $this->as($c1)->post("/api/competitions/$slug/entries", [
            'media' => [['file' => UploadedFile::fake()->image('moi.jpg')]],
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_comment_can_aim_at_a_duel_and_mention_a_member(): void
    {
        [$owner, $challenger, $fan] = User::factory()->count(3)->create();
        $challenger->update(['username' => 'zoe_k']);
        $slug = $this->as($owner)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Qui chante le mieux', 'content' => 'à moi', 'duration_hours' => 48])
            ->assertCreated()->json('data.slug');
        $this->as($challenger)->postJson("/api/competitions/$slug/entries", ['content' => 'à toi'])->assertCreated();
        $c = Competition::where('slug', $slug)->first();
        $match = $c->matches()->first();

        // Les duels du défi, pour en viser un
        $duels = $this->as($fan)->getJson("/api/app/competitions/$slug/comment-duels")->assertOk()->json('data');
        $this->assertSame($match->id, $duels[0]['id']);
        $this->assertStringContainsString(' contre ', $duels[0]['label']);

        // Un commentaire qui vise ce duel et cite @zoe_k ; un duel d'une autre compétition est ignoré
        $this->as($fan)->postJson("/api/app/competitions/$slug/comments", ['content' => 'Bravo @zoe_k !', 'match_id' => $match->id])->assertOk();
        $row = $this->as($fan)->getJson("/api/app/competitions/$slug/comments")->assertOk()->json('data.0');
        $this->assertSame($match->id, $row['duel']['id']);
        $this->assertSame('mention', $row['parts'][1]['type']);
        $this->assertSame('/@zoe_k', $row['parts'][1]['url']);
        $this->assertSame(' !', $row['parts'][2]['text']);

        // Le site affiche le nom en lien et le duel
        $html = $this->as($fan)->get("/c/$slug/commentaires", ['Accept' => 'application/json'])->assertOk()->json('html');
        $this->assertStringContainsString('class="mention" href="/@zoe_k"', $html);
        $this->assertStringContainsString('cm-duel', $html);

        // Chaque réponse mène à son duel ; pas celle du créateur
        $entries = collect($this->getJson("/api/competitions/$slug/entries")->assertOk()->json('data'));
        $this->assertSame($match->url(), $entries->firstWhere('participant.id', $challenger->id)['match_url']);
        $this->assertNull($entries->firstWhere('participant.id', $owner->id)['match_url']);
    }

    public function test_lead_change_and_challenge_accepted_notifications(): void
    {
        [$owner, $challenger, $v1, $v2, $v3, $v4, $v5] = User::factory()->count(7)->create();
        $slug = $this->as($owner)->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Qui chante le mieux', 'content' => 'à moi', 'duration_hours' => 48])
            ->assertCreated()->json('data.slug');
        $this->as($challenger)->postJson("/api/competitions/$slug/entries", ['content' => 'à toi'])->assertCreated();
        $this->assertTrue($owner->notifications()->get()->contains(fn ($n) => ($n->data['type'] ?? '') === 'challenge.accepted'));

        $m = CompetitionMatch::first();
        $ownerEntry = $m->entryA->participant_id === $owner->id ? $m->entry_a_id : $m->entry_b_id;
        $otherEntry = $ownerEntry === $m->entry_a_id ? $m->entry_b_id : $m->entry_a_id;
        foreach ([$v1, $v2] as $u) {
            $this->as($u)->postJson("/api/matches/{$m->id}/vote", ['entry_id' => $ownerEntry])->assertOk();
        }
        foreach ([$v3, $v4, $v5] as $u) {
            $this->as($u)->postJson("/api/matches/{$m->id}/vote", ['entry_id' => $otherEntry])->assertOk();
        }
        $lead = fn (User $u) => $u->fresh()->notifications()->get()->filter(fn ($n) => ($n->data['type'] ?? '') === 'match.lead');
        $this->assertSame('Tu es passé devant', $lead($challenger)->first()->data['title'] ?? null);
        $this->assertStringContainsString('est passé devant toi', $lead($owner)->first()->data['title'] ?? '');
    }

    public function test_coins_withdrawal_only_from_earnings(): void
    {
        config(['coins.provider' => 'test', 'coins.commission' => 20, 'coins.cashout' => ['rate' => 10, 'min' => 100]]);
        \App\Support\Features::save(['withdrawals' => true]);
        $coins = app(\App\Services\Coins\CoinService::class);
        [$fan, $star] = User::factory()->count(2)->create();
        $this->actingAs($fan)->post('/portefeuille/acheter', ['pack' => 'p1000']);
        // Les coins achetés ne se retirent pas
        $this->assertSame(0, $coins->withdrawable($fan));
        $this->actingAs($fan)->postJson('/portefeuille/retirer', ['coins' => 100, 'operator' => 'mpesa', 'phone' => '0810000001'])->assertStatus(422);
        // Cadeau de 250 : 200 gagnés
        $this->actingAs($fan)->postJson('/cadeau', ['to_type' => 'user', 'to_id' => $star->id, 'amount' => 250])->assertOk();
        $this->assertSame(200, $coins->withdrawable($star));
        $this->actingAs($star)->postJson('/portefeuille/retirer', ['coins' => 150, 'operator' => 'orange', 'phone' => '0890000001'])->assertOk();
        $this->assertSame(50, $coins->balance($star));
        $this->assertSame(50, $coins->withdrawable($star));
        $w = \App\Models\CoinWithdrawal::first();
        $this->assertSame(1500, (int) $w->amount);
        // Refus : les coins reviennent et redeviennent retirables
        $coins->rejectWithdrawal($w, 'Numéro invalide');
        $this->assertSame(200, $coins->balance($star));
        $this->assertSame(200, $coins->withdrawable($star));
        $this->actingAs($star)->get('/portefeuille?v=retirer')->assertOk()->assertSee('Retirer');
    }

    public function test_coins_purchase_gift_and_paid_votes(): void
    {
        config(['coins.provider' => 'test', 'coins.commission' => 20]);
        $coins = app(\App\Services\Coins\CoinService::class);
        [$org, $a, $b, $fan, $poor] = User::factory()->count(5)->create();

        // Achat d'un pack (mode test : crédité tout de suite)
        $this->actingAs($fan)->post('/portefeuille/acheter', ['pack' => 'p400'])->assertRedirect('/portefeuille');
        $this->assertSame(400, $coins->balance($fan));

        // Cadeau : 100 coins, le destinataire reçoit 80 (20 % pour la plateforme)
        $this->actingAs($fan)->postJson('/cadeau', ['to_type' => 'user', 'to_id' => $a->id, 'amount' => 100, 'message' => 'Bravo'])->assertOk();
        $this->assertSame(300, $coins->balance($fan));
        $this->assertSame(80, $coins->balance($a));
        $this->assertTrue($a->notifications()->get()->contains(fn ($n) => ($n->data['type'] ?? '') === 'gift.received'));

        // Concours payant : 5 coins par vote, plusieurs votes pour un seul candidat
        $slug = $this->as($org)->postJson('/api/competitions', [
            'type' => 'contest', 'title' => 'Plus belle voix', 'description' => 'Chante', 'vote_cost' => 5,
            'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg', 800, 400),
        ])->assertCreated()->json('data.slug');
        $c = Competition::where('slug', $slug)->first();
        $this->assertSame(5, (int) $c->vote_cost);
        $ea = \App\Models\CompetitionEntry::create(['competition_id' => $c->id, 'participant_type' => User::class, 'participant_id' => $a->id, 'status' => 'active', 'content' => 'a']);
        $eb = \App\Models\CompetitionEntry::create(['competition_id' => $c->id, 'participant_type' => User::class, 'participant_id' => $b->id, 'status' => 'active', 'content' => 'b']);
        $c->update(['status' => Competition::STATUS_LIVE]);
        $vote = fn (User $u, $e, int $q) => $this->actingAs($u)->postJson("/c/{$c->slug}/voter/{$e->id}", ['quantity' => $q]);
        $vote($fan, $ea, 10)->assertOk();
        $vote($fan, $ea, 4)->assertOk();
        $this->assertSame(14, (int) $ea->fresh()->votes_count);
        $this->assertSame(300 - 70, $coins->balance($fan));
        $this->assertSame((int) (70 * 0.8), $coins->balance($org)); // l'organisateur reçoit les coins, moins la commission
        $vote($fan, $eb, 1)->assertStatus(422); // un seul candidat
        $vote($poor, $ea, 3)->assertStatus(402); // pas assez de coins
        $this->assertSame(14, (int) $ea->fresh()->votes_count);

        // Duel payant : plusieurs votes, un seul camp, pas de retrait
        $duel = $this->as($org)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Pondu ou moambe', 'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'vote_cost' => 2,
        ])->assertCreated()->json('data');
        $m = CompetitionMatch::where('competition_id', $duel['id'])->first();
        $this->actingAs($fan)->postJson("/m/{$m->id}/vote", ['entry_id' => $m->entry_a_id, 'quantity' => 5])->assertOk();
        $this->actingAs($fan)->postJson("/m/{$m->id}/vote", ['entry_id' => $m->entry_a_id, 'quantity' => 1])->assertOk();
        $this->assertSame(6, (int) $m->fresh()->votes_a);
        $this->actingAs($fan)->postJson("/m/{$m->id}/vote", ['entry_id' => $m->entry_b_id])->assertStatus(422);
        $this->assertSame(230 - 12, $coins->balance($fan));

        // Duel gratuit : toujours un seul vote, retirable
        $free = $this->as($org)->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Gratuit', 'side_a_label' => 'A', 'side_b_label' => 'B',
        ])->assertCreated()->json('data');
        $fm = CompetitionMatch::where('competition_id', $free['id'])->first();
        $this->actingAs($fan)->postJson("/m/{$fm->id}/vote", ['entry_id' => $fm->entry_a_id, 'quantity' => 9])->assertOk();
        $this->assertSame(1, (int) $fm->fresh()->votes_a);
        $this->assertSame(218, $coins->balance($fan));

        // Le solde est toujours la somme du journal
        foreach ([$fan, $a, $org] as $u) {
            $this->assertSame((int) \App\Models\CoinTransaction::where('user_id', $u->id)->sum('amount'), $coins->balance($u));
        }
        $this->actingAs($fan)->get('/portefeuille')->assertOk()->assertSee('Historique');
    }
}
