<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CronRunner;
use App\Support\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** cron-job.org (adresse secrète) et stockage des médias (serveur ou R2). */
class CronAndStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_url_needs_the_secret(): void
    {
        $this->get('/api/cron/'.str_repeat('x', 40))->assertNotFound();
        $this->get('/api/cron/'.CronRunner::token())->assertOk()->assertSee('ok');
        $this->assertNotEmpty(CronRunner::settings()['last_run_at']);
        $old = CronRunner::token();
        CronRunner::regenerate();
        $this->get('/api/cron/'.$old)->assertNotFound();
    }

    public function test_admin_pages_render(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/taches-automatiques')->assertOk()->assertSee('cron-job.org')->assertSee('/api/cron/', false);
        $this->get('/admin/stockage')->assertOk()->assertSee('Cloudflare R2')->assertSee('Copier serveur');
    }

    public function test_copy_moves_existing_files_step_by_step_without_deleting(): void
    {
        Storage::fake('public');
        Storage::fake('r2');
        Storage::disk('public')->put('competitions/a.jpg', 'A');
        Storage::disk('public')->put('competitions/b.mp4', 'B');
        Storage::disk('public')->put('og/skip.png', 'x');

        MediaStorage::startCopy('to_r2');
        $c = MediaStorage::step(5);
        $this->assertFalse($c['running']);
        $this->assertSame(2, $c['copied']);
        Storage::disk('r2')->assertExists(['competitions/a.jpg', 'competitions/b.mp4']);
        Storage::disk('r2')->assertMissing('og/skip.png');
        Storage::disk('public')->assertExists('competitions/a.jpg'); // rien n'est supprimé
        $this->assertTrue(MediaStorage::settings()['synced']);

        // Relancer ne recopie pas
        MediaStorage::startCopy('to_r2');
        $this->assertSame(0, MediaStorage::step(5)['copied']);
    }

    public function test_local_files_still_served(): void
    {
        $dir = storage_path('app/public/tests');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/x.txt', 'hello');
        $this->get('/api/get-media/tests&x,txt')->assertOk();
        $this->get('/api/get-media/..&..&.env')->assertNotFound();
        $this->get('/api/get-media/tests&absent,txt')->assertNotFound();
        @unlink($dir.'/x.txt');
        @rmdir($dir);
    }
}
