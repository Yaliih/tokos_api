<?php

namespace Tests\Feature;

use App\Models\CompetitionMatch;
use App\Models\CompetitionVote;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Competition\VoteService;
use App\Services\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Anti-triche aux votes, notifications push, classements par Espace (institutions comprises) et ville. */
class TrustTest extends TestCase
{
    use RefreshDatabase;

    private function versus(User $creator): CompetitionMatch
    {
        Sanctum::actingAs($creator);
        $this->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Meilleur plat ?', 'category' => 'cuisine',
            'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2,
        ])->assertCreated();

        return CompetitionMatch::firstOrFail();
    }

    public function test_one_phone_cannot_vote_with_many_accounts(): void
    {
        [$creator, $a, $b, $c] = User::factory()->count(4)->create(['created_at' => now()->subDays(3)]);
        $match = $this->versus($creator);
        $vote = fn (User $u) => $this->withHeader('X-Device-Id', 'telephone-de-test-0001')->actingAs($u, 'sanctum')
            ->postJson("/api/matches/{$match->id}/vote", ['entry_id' => $match->entry_a_id]);

        $vote($a)->assertOk();
        $vote($b)->assertOk(); // 2e compte : accepté mais marqué suspect
        $vote($c)->assertStatus(429); // 3e compte sur le même téléphone : refusé
        $this->assertSame(2, CompetitionVote::count());
        $flagged = CompetitionVote::where('flagged', true)->first();
        $this->assertSame($b->id, $flagged->user_id);
        $this->assertStringContainsString('même téléphone', $flagged->flag_reason);

        // L'admin annule le vote suspect : le score est corrigé
        $this->assertSame(2, $match->fresh()->votes_a);
        app(VoteService::class)->cancelVote($flagged);
        $this->assertSame(1, $match->fresh()->votes_a);
        $this->assertSame(1, CompetitionVote::count());
    }

    public function test_signups_are_limited_per_phone(): void
    {
        config(['antifraud.signups_per_device' => 2]);
        User::factory()->count(2)->create(['signup_device' => 'telephone-de-test-0002']);
        $this->withUnencryptedCookie('tk_dev', 'telephone-de-test-0002')->post('/inscription', [
            'phone_code' => '+243', 'phone_number' => '899999999', 'verification_code' => '123456',
            'first_name' => 'Test', 'last_name' => 'Test', 'birth_date' => '2000-01-01', 'gender' => 'female', 'password' => 'secret12', 'password_confirmation' => 'secret12',
        ])->assertSessionHasErrors('phone_number');
    }

    public function test_push_subscription_and_delivery(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/push/cle')->assertOk()->assertJsonStructure(['key']);
        [, $phonePublic] = WebPush::newKeyPair();
        $this->actingAs($user)->postJson('/push/abonner', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-abc',
            'keys' => ['p256dh' => WebPush::b64($phonePublic), 'auth' => WebPush::b64(random_bytes(16))],
        ])->assertOk();
        // Une adresse qui n'est pas un service push est refusée
        $this->actingAs($user)->postJson('/push/abonner', ['endpoint' => 'https://exemple.com/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422);

        $status = 201;
        Http::fake(['fcm.googleapis.com/*' => function () use (&$status) {
            return Http::response('', $status);
        }]);
        app(\App\Services\Social\NotificationService::class)->send($user, 'challenge.received', 'On te défie', 'Réponds vite', '/defis-recus');
        Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0] ?? '', 'vapid t=')
            && $request->header('Content-Encoding')[0] === 'aes128gcm');

        // Abonnement expiré (410) : supprimé
        $status = 410;
        app(WebPush::class)->toUser($user->id, ['title' => 'x', 'body' => 'y']);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_school_and_city_rankings(): void
    {
        $school = \App\Models\Space::create(['owner_id' => User::factory()->create()->id, 'name' => 'UNIKIN', 'slug' => 'unikin', 'kind' => 'school', 'category' => 'education', 'subcategory' => 'universite']);
        [$a, $b] = User::factory()->count(2)->create(['city' => 'Kinshasa', 'school_space_id' => $school->id]);
        $season = \App\Models\Rating::currentSeason();
        foreach ([[$a, 12], [$b, 5]] as [$u, $pts]) {
            \App\Models\Rating::create(['rateable_type' => User::class, 'rateable_id' => $u->id, 'category' => 'all', 'season' => $season, 'points' => $pts, 'wins' => 1, 'matches' => 1, 'rating' => 1200]);
        }
        $club = \App\Models\Space::create(['owner_id' => $b->id, 'name' => 'Slam Club', 'slug' => 'slam-club', 'kind' => 'group', 'category' => 'arts']);
        $club->members()->attach($b->id, ['is_accepted' => true]);

        // Espaces : points des membres additionnés, triables par type
        $this->get('/classements?kind=space&periode=saison')->assertOk()->assertSee('UNIKIN')->assertSee('17')->assertSee('Slam Club')->assertDontSee('Universités');
        $this->get('/classements?kind=space&type=education&periode=saison')->assertOk()->assertSee('UNIKIN')->assertSee('Université')->assertDontSee('Slam Club');
        $this->get('/classements?kind=school&periode=saison')->assertOk()->assertSee('UNIKIN'); // ancien lien
        $this->get('/classements?kind=city&periode=saison')->assertOk()->assertSee('Kinshasa');
        $this->get('/classements?kind=user&espace=unikin&periode=saison')->assertOk()->assertSee($a->first_name);
        $this->get('/classements?kind=user&espace=slam-club&periode=saison')->assertOk()->assertSee($b->first_name)->assertDontSee($a->first_name.' '.$a->last_name);
        $this->get('/classements?kind=user&universite=unikin&periode=saison')->assertOk()->assertSee($a->first_name);
    }
}
