<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Duels chauds : photos de profil pour deux personnes ; pour un duel à deux choix, le contenu des choix. */
class HotRailTest extends TestCase
{
    use RefreshDatabase;

    public function test_versus_shows_choices_instead_of_avatars(): void
    {
        Storage::fake('public');
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $base = ['type' => 'duel', 'format' => 'versus', 'match_duration_hours' => 2];
        $this->postJson('/api/competitions', $base + ['title' => 'Meilleur plat ?', 'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe'])->assertCreated();
        $img = fn () => [['file' => UploadedFile::fake()->image('p.jpg'), 'type' => 'image']];
        $this->post('/api/competitions', $base + ['title' => 'Plus belle tenue ?', 'side_a_label' => 'Rouge', 'side_b_label' => 'Bleue', 'side_a_media' => $img(), 'side_b_media' => $img()], ['Accept' => 'application/json'])->assertCreated();

        $html = $this->get('/decouvrir')->assertOk()->getContent();
        if ($dump = env('HOT_RAIL_DUMP')) { file_put_contents($dump, $html); }
        $this->assertStringContainsString('hot-solo', $html);
        $this->assertStringContainsString('<span class="sa">Pondu</span><span class="sb">Moambe</span>', $html);
        $this->assertStringContainsString('is-text', $html);
        $this->assertStringNotContainsString($u->first_name.' contre '.$u->first_name, $html);
    }
}
