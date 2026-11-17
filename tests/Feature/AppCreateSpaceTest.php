<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Créer un Espace depuis l'app, comme sur le site. */
class AppCreateSpaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_lists_categories_and_creates_a_space(): void
    {
        $this->getJson('/api/app/spaces')->assertOk()->assertJsonPath('categories.0.value', 'education');
        $this->postJson('/api/app/spaces', ['name' => 'Crew Matonge'])->assertUnauthorized();

        $me = User::factory()->create();
        Sanctum::actingAs($me);
        $r = $this->postJson('/api/app/spaces', ['name' => 'Lycée Boboto', 'category' => 'education', 'subcategory' => 'lycee', 'bio' => 'Les Lions'])
            ->assertCreated();
        $space = Space::where('slug', $r->json('data.slug'))->firstOrFail();
        $this->assertSame($me->id, $space->owner_id);
        $this->assertSame('school', $space->kind);
        $this->assertTrue($space->canManage($me));

        $other = Space::create(['name' => 'Crew Matonge', 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
        $other->addMember($me);
        $this->getJson('/api/app/my-spaces')->assertOk()
            ->assertJsonPath('data.0.role', 'owner')->assertJsonPath('data.1.role', 'member')->assertJsonCount(2, 'data');

        $this->postJson('/api/app/spaces', ['name' => 'Lycée Boboto'])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Un Espace porte déjà ce nom. Choisis-en un autre.');
    }
}
