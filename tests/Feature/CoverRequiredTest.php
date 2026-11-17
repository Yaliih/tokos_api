<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Concours et tournoi : la couverture (photo ou vidéo) est obligatoire. */
class CoverRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_tournament_and_contest_need_a_cover(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach ([['type' => 'tournament', 'title' => 'Coupe du rire', 'max_entries' => 8], ['type' => 'contest', 'title' => 'Plus belle voix', 'description' => 'Chante !']] as $body) {
            $this->postJson('/api/competitions', $body + ['category' => 'humour'])
                ->assertStatus(422)
                ->assertJsonPath('errors.cover.0', "Un concours ou un tournoi a besoin d'une couverture : une image ou une courte vidéo.");
        }
    }
}
