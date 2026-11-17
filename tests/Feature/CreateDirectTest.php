<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Choisir « Concours » ou « Tournoi » mène droit au formulaire, sans repasser par « Que veux-tu lancer ? ». */
class CreateDirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_already_chosen_skips_the_type_screen(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['contest', 'tournament'] as $t) {
            $html = $this->get('/creer?type='.$t)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/data-type-step\s+hidden/', $html, $t);
            $this->assertStringNotContainsString('<span>Type</span>', $html);
        }
    }
}
