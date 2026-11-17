<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Les nouvelles options qu'on peut cacher depuis l'admin. */
class FeatureSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private function off(array $keys): void
    {
        Features::save(array_merge(Features::all(), array_fill_keys($keys, false)));
    }

    public function test_coins_switch_turns_off_everything_paid(): void
    {
        $this->off(['coins']);
        foreach (['buy_coins_web', 'buy_coins_app', 'gifts', 'paid_votes', 'withdrawals'] as $k) {
            $this->assertFalse(Features::on($k), $k);
        }
        $this->actingAs(User::factory()->create())->get('/portefeuille')->assertNotFound();
    }

    public function test_buy_coins_can_be_hidden_alone(): void
    {
        $this->off(['buy_coins_web']);
        $this->assertTrue(Features::on('coins'));
        $u = User::factory()->create();
        $this->actingAs($u)->get('/portefeuille?section=recharger')->assertOk()->assertDontSee('data-buy-form', false);
        $this->post('/portefeuille/acheter', ['pack' => 'x'])->assertNotFound();
    }

    public function test_publishing_switches(): void
    {
        $this->off(['versus', 'recording', 'prizes', 'editing']);
        $u = User::factory()->create();
        $this->actingAs($u)->get('/creer?type=duel')->assertOk()->assertDontSee('data-qc-toggle', false)->assertDontSee('data-record=', false);
        $this->post('/creer', ['type' => 'challenge', 'content' => 'Qui danse mieux que moi ?', 'prize' => '100 $'])->assertRedirect();
        $c = Competition::latest('id')->firstOrFail();
        $this->assertNull($c->prize);
        $this->get($c->url())->assertDontSee('data-open="edit-sheet"', false);
        $this->post('/c/'.$c->slug.'/modifier', ['title' => 'Autre'])->assertNotFound();
    }

    public function test_welcome_questions_can_be_hidden(): void
    {
        $u = User::factory()->create(['interests' => null, 'city' => null]);
        $this->off(['welcome_questions']);
        $this->actingAs($u)->get('/')->assertOk()->assertDontSee('Qu\'est-ce qui te plaît')->assertDontSee('Tu es dans quelle ville');
    }
}
