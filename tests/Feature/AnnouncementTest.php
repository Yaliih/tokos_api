<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use App\Services\Announcer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Annonces de l'admin : à tous, à une sélection, test, et rappel mensuel. */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_all_selected_test_and_monthly(): void
    {
        $users = User::factory()->count(3)->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $svc = app(Announcer::class);

        $all = Announcement::create(['title' => 'Bienvenue', 'body' => 'Le tournoi commence', 'url' => '/classements', 'audience' => 'all']);
        $this->assertSame(4, $svc->send($all));
        $this->assertSame('Bienvenue', $users[0]->fresh()->notifications()->first()->data['title']);
        $this->assertFalse($all->fresh()->is_active); // envoi unique : terminé

        $sel = Announcement::create(['title' => 'Pour toi', 'body' => 'x', 'audience' => 'selected', 'user_ids' => [$users[1]->id]]);
        $this->assertSame(1, $svc->send($sel));
        $this->assertSame(1, $users[2]->fresh()->notifications()->count());

        // Test : seulement l'admin, et l'annonce ne compte pas comme envoyée
        $this->assertSame(1, $svc->send($sel, [$admin->id]));
        $this->assertSame(1, $sel->fresh()->sent_count);

        // Rappel mensuel
        $this->travelTo('2026-11-11 09:00:00');
        $m = Announcement::create(['title' => 'Rappel', 'body' => 'Reviens jouer', 'audience' => 'all', 'repeat' => 'monthly', 'next_send_at' => now()->subMinute(), 'is_active' => true]);
        $this->assertSame(1, $svc->sendDue());
        $this->assertSame('2026-12-11 08:59', $m->fresh()->next_send_at->format('Y-m-d H:i'));
        $this->assertTrue($m->fresh()->is_active);
        $this->assertSame(0, $svc->sendDue()); // pas avant le mois prochain

        // Groupes : nouveaux inscrits (24 h), plus revenus depuis 30 jours
        $old = User::factory()->create();
        $old->forceFill(['created_at' => now()->subDays(60), 'last_seen_at' => now()->subDays(40), 'is_active' => true])->saveQuietly();
        $this->assertContains($old->id, \App\Support\Audiences::ids('inactive', 30));
        $this->assertNotContains($old->id, \App\Support\Audiences::ids('new', 1));
        $fresh = User::factory()->create(['created_at' => now()->subHours(3)]);
        $this->assertContains($fresh->id, \App\Support\Audiences::ids('new', 1));
        foreach (array_keys(\App\Support\Audiences::SEGMENTS) as $k) {
            \App\Support\Audiences::count($k, 5, 'Kinshasa'); // aucune requête ne casse
        }
        $seg = Announcement::create(['title' => 'Reviens', 'body' => 'x', 'audience' => 'segment', 'segment' => ['key' => 'inactive', 'n' => 30]]);
        $this->assertSame(count(\App\Support\Audiences::ids('inactive', 30)), $svc->send($seg));
        $this->assertSame('Reviens', $old->fresh()->notifications()->latest()->first()->data['title']);

        $this->actingAs($admin)->get('/admin/announcements')->assertOk()->assertSee('Nouvelle annonce');
    }
}
