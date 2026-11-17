<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Rules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Règles réglées dans l'admin : le serveur et l'app les suivent. */
class RulesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Rules::flush();
        parent::tearDown();
    }

    public function test_admin_rules_change_limits_everywhere(): void
    {
        Rules::save([
            'competition.media.max_cover_video_seconds' => 45,
            'competition.media.max_upload_mb' => 80,
            'competition.limits.user.tournament_max_entries' => 50, // arrondi à 32
            'competition.limits.user.allow_jury' => true,
        ]);
        $this->assertSame(45, config('competition.media.max_cover_video_seconds'));
        $this->assertSame(32, config('competition.limits.user.tournament_max_entries'));
        $this->assertTrue(config('competition.limits.user.allow_jury'));
        $this->assertSame(80 * 1024, \App\Services\MediaUploader::maxKb());

        // Relu au démarrage depuis la base
        config(['competition.media.max_cover_video_seconds' => 30]);
        Rules::apply();
        $this->assertSame(45, config('competition.media.max_cover_video_seconds'));

        // L'app reçoit les nouvelles limites
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/competitions/options')->assertJsonPath('data.media_limits.max_cover_video_seconds', 45);
    }
}
