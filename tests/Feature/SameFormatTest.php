<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Un duel se joue toujours dans le même format des deux côtés : texte/texte, vidéo/vidéo, audio/audio, photo/photo. */
class SameFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_versus_choices_share_the_same_format(): void
    {
        Storage::fake('public');
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $base = ['type' => 'duel', 'format' => 'versus', 'title' => 'Meilleur plat ?', 'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2];
        $img = fn () => [['file' => UploadedFile::fake()->image('p.jpg'), 'type' => 'image']];

        // Choix 1 en texte, choix 2 en photo : refusé
        $this->post('/api/competitions', $base + ['side_b_media' => $img()], ['Accept' => 'application/json'])->assertStatus(422);
        // Choix 1 en photo, choix 2 en texte : refusé
        $this->post('/api/competitions', $base + ['side_a_media' => $img()], ['Accept' => 'application/json'])->assertStatus(422);
        // Photo contre photo, et texte contre texte : accepté
        $this->post('/api/competitions', $base + ['side_a_media' => $img(), 'side_b_media' => $img()], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson('/api/competitions', $base)->assertCreated();
        $this->assertSame(0, Competition::where('status', 'draft')->count());
    }

    public function test_tournament_entries_follow_the_first_one(): void
    {
        Storage::fake('public');
        [$owner, $a, $b] = User::factory()->count(3)->create();
        Sanctum::actingAs($owner);
        $slug = $this->post('/api/competitions', ['type' => 'tournament', 'cover' => UploadedFile::fake()->image('c.jpg'), 'title' => 'Coupe', 'media_types' => ['text', 'image', 'video']], ['Accept' => 'application/json'])->assertCreated()->json('data.slug');

        Sanctum::actingAs($a);
        $this->post("/api/competitions/$slug/entries", ['content' => 'Ma blague', 'photo' => UploadedFile::fake()->image('m.jpg')], ['Accept' => 'application/json'])->assertCreated();
        // Le premier a envoyé une photo : le suivant doit aussi envoyer une photo
        Sanctum::actingAs($b);
        $this->postJson("/api/competitions/$slug/entries", ['content' => 'Juste du texte'])->assertStatus(422);
        $this->post("/api/competitions/$slug/entries", ['photo' => UploadedFile::fake()->image('n.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('image', Competition::where('slug', $slug)->first()->entryFormat());
    }

    public function test_text_challenge_only_accepts_text_answers(): void
    {
        Storage::fake('public');
        [$owner, $x] = User::factory()->count(2)->create();
        Sanctum::actingAs($owner);
        $slug = $this->postJson('/api/competitions', ['type' => 'challenge', 'title' => 'Ta meilleure punchline', 'content' => 'Voici la mienne'])->assertCreated()->json('data.slug');
        Sanctum::actingAs($x);
        $this->post("/api/competitions/$slug/entries", ['content' => 'La mienne', 'photo' => UploadedFile::fake()->image('m.jpg')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson("/api/competitions/$slug/entries", ['content' => 'La mienne'])->assertCreated();
    }
}
