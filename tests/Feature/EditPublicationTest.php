<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Modifier sa publication : permis tant que personne n'a répondu ni voté, jamais par un autre. */
class EditPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function challenge(User $u): Competition
    {
        $this->actingAs($u)->post('/creer', ['type' => 'challenge', 'content' => "Qui rappe mieux que moi ?\nUne prise.", 'category' => 'rap'])->assertRedirect();

        return Competition::latest('id')->firstOrFail();
    }

    public function test_owner_can_edit_before_any_activity(): void
    {
        $u = User::factory()->create();
        $c = $this->challenge($u);
        $this->get($c->url())->assertSee('data-open="edit-sheet"', false)->assertSee('Enregistrer');
        $this->post('/c/'.$c->slug.'/modifier', ['title' => 'Qui slamme mieux que moi ?', 'text' => 'Deux prises max.', 'category' => 'poesie'])->assertRedirect();
        $c->refresh();
        $this->assertSame('Qui slamme mieux que moi ?', $c->title);
        $this->assertSame('poesie', $c->category);
        $this->assertNotNull($c->edited_at);
        $this->assertSame("Qui slamme mieux que moi ?\nDeux prises max.", $c->entries()->first()->content);
        $this->get($c->url())->assertSee('modifié');
    }

    public function test_nothing_changes_once_time_is_up_whatever_the_type(): void
    {
        $u = User::factory()->create();
        $c = $this->challenge($u);
        // Le temps est fini, même si la clôture automatique n'est pas encore passée
        $c->forceFill(['ends_at' => now()->subMinute()])->saveQuietly();
        $this->get($c->url())->assertDontSee('data-open="edit-sheet"', false);
        $this->post('/c/'.$c->slug.'/modifier', ['title' => 'Trop tard'])->assertRedirect();
        $this->assertNotSame('Trop tard', $c->fresh()->title);
        \Laravel\Sanctum\Sanctum::actingAs($u);
        $this->getJson('/api/app/competitions/'.$c->slug.'/edit')->assertJsonPath('data.can', false);
        $this->assertTrue($c->fresh()->isOver());

        // Concours et tournoi aussi, et duel dont l'invitation a expiré
        foreach (['contest', 'tournament'] as $type) {
            $c->forceFill(['type' => $type, 'status' => 'live', 'ends_at' => now()->subMinute()])->saveQuietly();
            $this->assertFalse(app(\App\Services\Competition\CompetitionEditor::class)->rules($c->fresh(), $u)['can'], $type);
        }
        $c->forceFill(['type' => 'duel', 'status' => 'open', 'ends_at' => null, 'registration_ends_at' => now()->subMinute()])->saveQuietly();
        $this->assertFalse(app(\App\Services\Competition\CompetitionEditor::class)->rules($c->fresh(), $u)['can']);
    }

    public function test_others_cannot_edit(): void
    {
        $c = $this->challenge(User::factory()->create());
        $this->actingAs(User::factory()->create())->post('/c/'.$c->slug.'/modifier', ['title' => 'Piraté'])->assertRedirect();
        $this->assertNotSame('Piraté', $c->fresh()->title);
    }

    public function test_challenge_is_locked_once_someone_votes_or_answers(): void
    {
        $u = User::factory()->create();
        $c = $this->challenge($u);
        $c->update(['votes_count' => 1]);
        $this->get($c->url())->assertSee('ne peut plus changer');
        $this->post('/c/'.$c->slug.'/modifier', ['title' => 'Autre chose'])->assertRedirect();
        $this->assertNotSame('Autre chose', $c->fresh()->title);
    }

    public function test_contest_keeps_description_and_prize_editable_after_entries(): void
    {
        $u = User::factory()->create();
        $c = $this->challenge($u);
        $c->update(['type' => 'contest', 'title' => 'Miss Kin', 'description' => 'Avant']);
        $c->entries()->create(['submitted_by' => User::factory()->create()->id, 'status' => 'active', 'content' => 'Moi']);
        $this->post('/c/'.$c->slug.'/modifier', ['title' => 'Autre titre', 'text' => 'Après', 'prize' => '200 $', 'rules' => 'x'])->assertRedirect();
        $c->refresh();
        $this->assertSame('Miss Kin', $c->title);
        $this->assertSame('Après', $c->description);
        $this->assertSame('200 $', $c->prize);
        $this->assertNull($c->rules);
    }
}
