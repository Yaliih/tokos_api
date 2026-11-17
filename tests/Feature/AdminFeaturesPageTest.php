<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeaturesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_all_switches(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/fonctions')->assertOk()
            ->assertSee('Monétisation (coins et paiements)')->assertSee('Achat de coins')->assertSee('Publication')
            ->assertSee('Modifier ses publications')->assertSee('Site web des Espaces');
    }
}
