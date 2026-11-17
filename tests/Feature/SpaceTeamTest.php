<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Espace : adhésion sur validation, ajout, rôles (publieur, modérateur, administrateur), qui modifie quoi. */
class SpaceTeamTest extends TestCase
{
    use RefreshDatabase;

    private function space(User $owner, bool $approval = false): Space
    {
        $s = Space::create(['name' => 'Lycée Boboto', 'owner_id' => $owner->id, 'is_active' => true, 'join_approval' => $approval]);
        $s->addManager($owner, 'admin');
        $s->addMember($owner);

        return $s;
    }

    public function test_join_with_approval_then_accept_from_the_app(): void
    {
        [$owner, $fan] = User::factory()->count(2)->create();
        $s = $this->space($owner, true);

        Sanctum::actingAs($fan);
        $this->postJson('/api/app/spaces/'.$s->slug.'/join')->assertOk()->assertJsonPath('is_pending', true)->assertJsonPath('is_member', false);
        $this->assertFalse($s->isMember($fan));
        $this->getJson('/api/app/spaces/'.$s->slug.'/team')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/app/inbox')->assertJsonPath('data.0.kind', 'member');
        $this->getJson('/api/app/spaces/'.$s->slug.'/team')->assertOk()->assertJsonCount(1, 'data.pending');
        $this->postJson('/api/app/spaces/'.$s->slug.'/members/'.$fan->id.'/decision', ['decision' => 'accept'])->assertOk();
        $this->assertTrue($s->fresh()->isMember($fan));
        $this->assertSame(2, $s->fresh()->members()->count());
    }

    public function test_roles_decide_who_publishes_validates_and_edits(): void
    {
        [$owner, $pub, $mod, $other] = User::factory()->count(4)->create();
        $s = $this->space($owner);

        $this->actingAs($owner);
        // Inviter n'ajoute pas : la personne doit accepter
        $this->post('/espaces/'.$s->slug.'/membres', ['username' => $pub->username])->assertRedirect();
        $this->assertFalse($s->fresh()->isMember($pub));
        $this->assertTrue($s->fresh()->isInvited($pub));
        $this->get('/espaces/'.$s->slug.'/membres')->assertSee('Invitations en attente');
        $this->actingAs($pub);
        $this->get('/defis-recus')->assertSee('invite à rejoindre', false);
        $this->post('/espaces/'.$s->slug.'/invitation', ['decision' => 'accept'])->assertRedirect();
        $this->assertTrue($s->fresh()->isMember($pub));
        $this->actingAs($owner);
        $this->post('/espaces/'.$s->slug.'/membres/'.$pub->id.'/role', ['role' => 'viewer'])->assertRedirect();
        $this->post('/espaces/'.$s->slug.'/membres/'.$mod->id.'/role', ['role' => 'editor'])->assertRedirect();
        $s = $s->fresh();
        $this->assertTrue($s->canPublish($pub) && ! $s->canModerate($pub) && ! $s->canEditInfo($pub));
        $this->assertTrue($s->canPublish($mod) && $s->canModerate($mod) && ! $s->canEditInfo($mod));
        $this->assertContains($s->id, $pub->publishableSpaceIds());
        $this->get('/espaces/'.$s->slug.'/membres')->assertOk()->assertSee('Modérateur');

        // Le modérateur ajoute et valide, mais ne change ni le nom ni les rôles, et ne retire pas l'équipe
        Sanctum::actingAs($mod);
        $this->postJson('/api/app/spaces/'.$s->slug.'/members', ['username' => '@'.$other->username])->assertOk();
        Sanctum::actingAs($other);
        $this->getJson('/api/app/inbox')->assertJsonPath('data.0.kind', 'space_invite');
        $this->postJson('/api/app/spaces/'.$s->slug.'/invitation', ['decision' => 'accept'])->assertOk();
        Sanctum::actingAs($mod);
        $this->postJson('/api/app/spaces/'.$s->slug.'/edit', ['name' => 'Autre nom'])->assertForbidden();
        $this->postJson('/api/app/spaces/'.$s->slug.'/members/'.$other->id.'/role', ['role' => 'admin'])->assertStatus(403);
        $this->postJson('/api/app/spaces/'.$s->slug.'/members/'.$pub->id.'/remove')->assertStatus(422);
        $this->postJson('/api/app/spaces/'.$s->slug.'/members/'.$other->id.'/remove')->assertOk();
        $this->assertFalse($s->fresh()->isMember($other));

        // Le publieur ne valide rien
        Sanctum::actingAs($pub);
        $this->getJson('/api/app/spaces/'.$s->slug.'/team')->assertForbidden();

        // L'administrateur modifie l'Espace, une option à la fois
        Sanctum::actingAs($owner);
        $this->postJson('/api/app/spaces/'.$s->slug.'/edit', ['join_approval' => true])->assertOk()->assertJsonPath('data.join_approval', true)->assertJsonPath('data.name', 'Lycée Boboto');
        $this->postJson('/api/app/spaces/'.$s->slug.'/edit', ['name' => 'Lycée Boboto Lions'])->assertOk()->assertJsonPath('data.name', 'Lycée Boboto Lions');
        $this->getJson('/api/app/spaces/'.$s->slug)->assertJsonPath('data.can_edit', true)->assertJsonPath('data.my_role', 'owner');
    }
}
