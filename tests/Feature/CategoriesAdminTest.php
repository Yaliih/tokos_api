<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Categories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Les disciplines viennent de l'admin (base), pas du code. */
class CategoriesAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_list_is_in_the_database_and_admin_changes_apply_everywhere(): void
    {
        // La liste de départ est posée par la migration, rien dans le code
        $this->assertCount(16, Categories::defaults());
        $this->assertArrayHasKey('chant', config('competition.categories'));

        // L'admin ajoute « Théâtre » et désactive « Jeux vidéo »
        $rows = collect(Categories::list())->map(fn ($r) => $r['key'] === 'gaming' ? ['active' => false] + $r : $r)->all();
        $rows[] = ['label' => 'Théâtre', 'active' => true];
        Categories::save($rows);

        $this->assertContains('Théâtre', config('competition.categories'));
        $this->assertArrayNotHasKey('gaming', config('competition.categories'));
        $this->assertSame('Jeux vidéo', Categories::label('gaming')); // le nom reste sur les anciennes publications

        // L'app reçoit la nouvelle liste
        Sanctum::actingAs(User::factory()->create());
        $cats = collect($this->getJson('/api/app/config')->assertOk()->json('data.categories'))->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Théâtre', $cats);
        $this->assertStringNotContainsString('Jeux vidéo', $cats);

        // Une discipline désactivée ne peut plus être choisie à la publication
        $this->postJson('/api/competitions', ['type' => 'challenge', 'content' => 'Qui joue mieux ?', 'category' => 'gaming'])->assertStatus(422);
        $this->postJson('/api/competitions', ['type' => 'challenge', 'content' => 'Qui joue mieux ?', 'category' => 'theatre'])->assertCreated();

        // « Remettre la liste par défaut »
        Categories::reset();
        $this->assertCount(16, config('competition.categories'));
        $this->assertArrayHasKey('gaming', config('competition.categories'));
    }
}
