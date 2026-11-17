<?php

namespace Tests\Feature;

use App\Jobs\SendNotifications;
use App\Jobs\SendPush;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\User;
use App\Services\Social\NotificationService;
use App\Services\Social\Pulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Relances des votants (favori gagné, dépassé, de retour, nouvelle compétition) et file d'attente. */
class SupportersQueueTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function contest(User $owner, array $players, string $title = 'Plus belle voix'): array
    {
        Storage::fake('public');
        $slug = $this->as($owner)->post('/api/competitions', [
            'type' => 'contest', 'title' => $title, 'description' => 'Chante !', 'cover' => UploadedFile::fake()->image('c.jpg'), 'votes_per_user' => 3,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.slug');
        $entries = [];
        foreach ($players as $u) {
            $this->as($u)->post("/api/competitions/$slug/entries", ['content' => 'Ma voix', 'photo' => UploadedFile::fake()->image('m.jpg')], ['Accept' => 'application/json'])->assertCreated();
            $entries[] = CompetitionEntry::where('participant_id', $u->id)->latest('id')->first();
        }

        return [$slug, $entries];
    }

    private function vote(User $v, string $slug, CompetitionEntry $e): void
    {
        $this->as($v)->postJson("/api/competitions/$slug/entries/{$e->id}/vote")->assertOk();
    }

    private function types(User $u): array
    {
        return $u->fresh()->notifications()->get()->pluck('data.type')->all();
    }

    public function test_voters_follow_their_favourite_and_come_back(): void
    {
        [$owner, $a, $b] = User::factory()->count(3)->create();
        [$fanA, $fanB, $fanB2] = User::factory()->count(3)->create();

        [$slug, [$ea, $eb]] = $this->contest($owner, [$a, $b]);
        $this->as($owner)->postJson("/api/competitions/$slug/start")->assertOk();

        // A mène, puis B le dépasse : le fan de A est prévenu
        $this->vote($fanA, $slug, $ea);
        $this->vote($fanB, $slug, $ea);
        $this->vote($fanB2, $slug, $ea);
        app(Pulse::class)->run();
        $this->vote($fanB, $slug, $eb);
        $this->vote($fanB2, $slug, $eb);
        \DB::table('competition_entries')->where('id', $eb->id)->update(['votes_count' => 5, 'score' => 5]);
        app(Pulse::class)->run();
        $this->assertContains('supported.progress', $this->types($fanA));
        $titles = $fanA->fresh()->notifications()->get()->pluck('data.title')->all();
        $this->assertContains('Ton favori est dépassé', $titles);

        // Fin : chacun apprend la place de son favori
        app(\App\Services\Competition\CompetitionService::class)->complete(Competition::where('slug', $slug)->first());
        $this->assertContains('supported.result', $this->types($fanA));
        $this->assertNotContains('supported.result', $this->types($a)); // les joueurs ont déjà leur résultat

        // Nouveau concours où B participe : ses fans le savent (« ton favori est de retour »), et tous les votants voient le nouveau concours
        $owner2 = User::factory()->create();
        $this->contest($owner2, [$b], 'Meilleur rappeur');
        $this->assertContains('favorite.joined', $this->types($fanB));
        $this->assertContains('competition.new', $this->types($fanA));

        // Pas plus d'une nouvelle compétition tous les 3 jours
        $this->contest(User::factory()->create(), [], 'Meilleur danseur');
        $this->assertSame(1, collect($this->types($fanA))->filter(fn ($t) => $t === 'competition.new')->count());

        // Fonction coupée : plus de relances
        \App\Support\Features::save(['supporter_alerts' => false] + \App\Support\Features::all());
        $this->travel(4)->days();
        $before = $fanA->fresh()->notifications()->count();
        $this->contest(User::factory()->create(), [], 'Meilleur DJ');
        $this->assertSame($before, $fanA->fresh()->notifications()->count());
    }

    public function test_heavy_sends_go_through_the_queue(): void
    {
        Queue::fake();
        $few = User::factory()->count(3)->create();
        $many = User::factory()->count(60)->create();
        $svc = app(NotificationService::class);

        // Peu de monde : la cloche tout de suite, le push dans la file
        $svc->send($few->pluck('id'), 'comment.new', 'Salut', 'Texte', '/');
        $this->assertSame(1, $few[0]->notifications()->count());
        Queue::assertPushed(SendPush::class, fn ($j) => count($j->ids) === 3);

        // Beaucoup de monde : tout l'envoi dans la file
        $svc->send($many->pluck('id'), 'comment.new', 'Salut', 'Texte', '/');
        $this->assertSame(0, $many[0]->notifications()->count());
        Queue::assertPushed(SendNotifications::class, fn ($j) => count($j->ids) === 60);
    }

    public function test_scheduler_runs_and_reports_health(): void
    {
        $this->artisan('schedule:run')->assertSuccessful();
        $this->artisan('cron:etat')->expectsOutputToContain('Dernier passage du planificateur')->assertSuccessful();
    }
}
