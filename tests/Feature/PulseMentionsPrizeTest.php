<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Space;
use App\Models\User;
use App\Services\Social\Pulse;
use App\Support\Mentions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Publications automatiques du fil, alertes de classement, mentions @ et remise du prix. */
class PulseMentionsPrizeTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    /** Un concours lancé avec trois candidats. @return array{0: Competition, 1: array<int, CompetitionEntry>, 2: User} */
    private function liveContest(array $extra = []): array
    {
        Storage::fake('public');
        [$owner, $a, $b, $c] = User::factory()->count(4)->create();
        $slug = $this->as($owner)->post('/api/competitions', [
            'type' => 'contest', 'title' => 'Plus belle voix', 'description' => 'Chante !', 'cover' => UploadedFile::fake()->image('c.jpg'),
        ] + $extra, ['Accept' => 'application/json'])->assertCreated()->json('data.slug');
        $entries = [];
        foreach ([$a, $b, $c] as $u) {
            $this->as($u)->post("/api/competitions/$slug/entries", ['content' => 'Ma voix', 'photo' => UploadedFile::fake()->image('m.jpg')], ['Accept' => 'application/json'])->assertCreated();
            $entries[] = CompetitionEntry::where('participant_id', $u->id)->first();
        }
        $this->as($owner)->postJson("/api/competitions/$slug/start")->assertOk();

        return [Competition::where('slug', $slug)->first(), $entries, $owner];
    }

    private function votes(CompetitionEntry $e, int $n): void
    {
        $e->forceFill(['votes_count' => $n, 'score' => $n])->saveQuietly();
    }

    public function test_pulse_posts_leader_changes_and_alerts_participants(): void
    {
        [$comp, [$a, $b, $c]] = $this->liveContest();
        $pulse = app(Pulse::class);

        $this->votes($a, 5);
        $this->votes($b, 2);
        $pulse->run();
        $this->assertSame(1, Activity::where('verb', 'contest.leader')->count());
        $this->assertSame((int) $a->participant_id, (int) Activity::where('verb', 'contest.leader')->value('actor_id'));
        $this->assertSame(0, \DB::table('notifications')->where('data', 'like', '%contest.progress%')->count()); // 1re photo : pas d'alerte

        // B passe devant : l'ancien premier est prévenu, B aussi ; pas de 2e carte dans les 3 h
        $this->votes($b, 9);
        $pulse->run();
        $this->assertSame(1, Activity::where('verb', 'contest.leader')->count());
        $aNotes = User::find($a->participant_id)->notifications()->get()->pluck('data.title')->all();
        $this->assertContains('On t\'a dépassé', $aNotes);
        $this->assertContains('Tu es premier !', User::find($b->participant_id)->notifications()->get()->pluck('data.title')->all());

        // Plus tard, A repasse premier : une carte « repasse premier » dans le fil
        $this->travel(4)->hours();
        $this->votes($a, 12);
        $pulse->run();
        $again = Activity::where('verb', 'contest.leader')->latest('id')->first();
        $this->assertTrue((bool) $again->data['again']);
        $this->getJson('/api/app/home')->assertOk()->assertSee('repasse premier au concours');

        // Fonctions coupées : plus rien
        \App\Support\Features::save(['auto_feed' => false, 'progress_alerts' => false] + \App\Support\Features::all());
        $this->travel(4)->hours();
        $this->votes($c, 30);
        $before = Activity::count();
        $pulse->run();
        $this->assertSame($before, Activity::count());
    }

    public function test_pulse_last_call_entries_step_and_tight_duel(): void
    {
        [$comp] = $this->liveContest();
        $comp->forceFill(['ends_at' => now()->addHours(2), 'entries_count' => 12])->saveQuietly();
        app(Pulse::class)->run();
        $this->assertSame(1, Activity::where('verb', 'competition.last_call')->count());
        $this->assertSame(1, Activity::where('verb', 'competition.entries')->count());
        app(Pulse::class)->run(); // jamais deux fois
        $this->assertSame(1, Activity::where('verb', 'competition.last_call')->count());
        $this->assertSame(1, Activity::where('verb', 'competition.entries')->count());
        // Le fil montre une carte par compétition (la plus récente)
        $this->get('/')->assertOk()->assertSee('10 participants');
        $line = \App\Support\Presenter::activity(Activity::where('verb', 'competition.last_call')->first());
        $this->assertStringStartsWith('Dernières heures pour voter dans « Plus belle voix »', $line['text']);

        // Duel serré dans ses 3 dernières heures
        Storage::fake('public');
        [$x, $y] = User::factory()->count(2)->create();
        $this->as($x)->postJson('/api/competitions', ['type' => 'duel', 'format' => 'challenge', 'opponent' => $y->username, 'content' => 'Prêt ?', 'duration_hours' => 6])->assertCreated();
        $duel = Competition::where('type', 'duel')->first();
        $inv = \App\Models\CompetitionInvitation::where('competition_id', $duel->id)->first();
        $this->as($y)->postJson('/api/invitations/'.$inv->id.'/accept', ['content' => 'Toujours'])->assertSuccessful();
        $m = \App\Models\CompetitionMatch::where('competition_id', $duel->id)->first();
        $m->forceFill(['status' => 'voting', 'votes_a' => 5, 'votes_b' => 4, 'ends_at' => now()->addHour()])->saveQuietly();
        app(Pulse::class)->run();
        app(Pulse::class)->run();
        $this->assertSame(1, Activity::where('verb', 'match.tight')->count());
    }

    public function test_mentions_link_people_and_spaces_and_notify_them(): void
    {
        Storage::fake('public');
        [$me, $ami] = User::factory()->count(2)->create();
        $owner = User::factory()->create();
        $space = Space::create(['owner_id' => $owner->id, 'name' => 'Les Lions', 'slug' => 'les-lions', 'is_active' => true]);
        $space->addManager($owner, 'admin');

        $text = 'Merci @'.$ami->username.' et @les-lions. Et @inconnu.';
        $parts = Mentions::parts($text);
        $this->assertSame(['text', 'mention', 'text', 'mention', 'text'], array_column($parts, 'type'));
        $this->assertSame('/espaces/les-lions', $parts[3]['url']);
        $this->assertStringContainsString('<a class="mention" href="/@'.$ami->username.'">', Mentions::html($text));

        // Une description qui cite : les personnes citées sont prévenues, le texte s'affiche en liens
        $slug = $this->as($me)->post('/api/competitions', [
            'type' => 'contest', 'title' => 'Plus belle voix', 'description' => $text, 'cover' => UploadedFile::fake()->image('c.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.slug');
        $this->assertSame('mention', $ami->notifications()->first()->data['type']);
        $this->assertSame('mention', $owner->notifications()->first()->data['type']);
        $this->getJson('/api/competitions/'.$slug)->assertOk()->assertJsonPath('data.mentions.les-lions.type', 'space');
        $this->get('/c/'.$slug)->assertOk()->assertSee('class="mention"', false);

        // Commentaire qui cite un Espace : parts avec le lien de l'Espace
        $this->as($ami)->postJson('/api/app/competitions/'.$slug.'/comments', ['content' => 'Bravo @les-lions'])->assertOk();
        $this->assertSame(2, $owner->notifications()->count());
    }

    public function test_prize_proof_is_the_last_step(): void
    {
        [$comp, , $owner] = $this->liveContest(['prize' => 'Un repas offert chez Mama Kin']);
        $intruder = User::factory()->create();
        app(\App\Services\Competition\CompetitionService::class)->complete($comp);
        $comp->refresh();
        // L'organisateur est invité à filmer la remise
        $this->assertSame('prize.reminder', $owner->notifications()->latest('created_at')->get()->firstWhere('data.type', 'prize.reminder')?->data['type']);
        $this->as($owner)->getJson('/api/competitions/'.$comp->slug)->assertJsonPath('data.can_publish_prize', true);

        $video = UploadedFile::fake()->create('remise.mp4', 500, 'video/mp4');
        $this->as($intruder)->post('/api/app/competitions/'.$comp->slug.'/prize-proof', ['files' => [$video]], ['Accept' => 'application/json'])->assertStatus(403);
        $this->as($owner)->post('/api/app/competitions/'.$comp->slug.'/prize-proof', ['files' => [UploadedFile::fake()->image('remise.jpg')], 'text' => 'Bravo !'], ['Accept' => 'application/json'])->assertOk();

        $comp->refresh();
        $this->assertNotNull($comp->prize_proof_at);
        $this->assertSame(1, Activity::where('verb', 'prize.delivered')->count());
        $this->getJson('/api/competitions/'.$comp->slug)->assertJsonPath('data.prize_delivered', true)->assertJsonPath('data.prize_proof.text', 'Bravo !');
        $this->get('/c/'.$comp->slug)->assertOk()->assertSee('Remise du prix');
        $this->get('/')->assertOk()->assertSee('reçoit son prix');

        // Rappel 3 jours après seulement s'il n'y a pas de vidéo
        $this->travel(4)->days();
        $this->assertSame(0, app(Pulse::class)->run()['reminders']);
    }
}
