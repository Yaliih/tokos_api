<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Les erreurs de validation : en français par défaut, en anglais sur demande. */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_are_french_by_default_and_english_on_request(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Par défaut : français, avec le nom du champ en français
        $this->postJson('/api/app/profile', ['bio' => str_repeat('a', 400)])->assertStatus(422)
            ->assertJsonPath('errors.bio.0', 'Le champ bio ne doit pas dépasser 300 caractères.');
        $this->postJson('/api/app/profile', ['first_name' => ''])->assertStatus(422)
            ->assertJsonPath('errors.first_name.0', 'Ton prénom est obligatoire.');

        // L'app peut demander l'anglais
        $this->postJson('/api/app/profile', ['bio' => str_repeat('a', 400)], ['X-Locale' => 'en'])->assertStatus(422)
            ->assertJsonPath('errors.bio.0', 'The bio field must not be greater than 300 characters.');

        // Le navigateur en anglais ne change rien : Sosa reste en français
        $this->postJson('/api/app/profile', ['bio' => str_repeat('a', 400)], ['Accept-Language' => 'en-US'])->assertStatus(422)
            ->assertJsonPath('errors.bio.0', 'Le champ bio ne doit pas dépasser 300 caractères.');
    }

    public function test_hardcoded_auth_messages_are_translated(): void
    {
        $u = \App\Models\User::factory()->create(['phone_code' => '+229', 'phone_number' => '97000001']);
        $body = ['phone_code' => '+229', 'phone_number' => '97000001'];

        $this->postJson('/api/auth/send-verification-code', $body)
            ->assertStatus(422)
            ->assertJsonPath('errors.phone_number.0', 'Ce numéro a déjà un compte. Connecte-toi.');

        $this->postJson('/api/auth/send-verification-code', $body, ['X-Locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone_number.0', 'This phone number is already registered. Please login instead.');
    }
}
