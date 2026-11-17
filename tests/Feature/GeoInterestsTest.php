<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\Space;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Compétitions réservées à une ville (position du téléphone), centres d'intérêt, catégories d'Espace, jury. */
class GeoInterestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['places.geocoder' => 'local']); // pas d'appel réseau en test
    }

    public function test_coordinates_give_the_right_city(): void
    {
        $this->assertSame('Kinshasa', Geo::nearest(-4.36, 15.25));     // Ngaliema
        $this->assertSame('Lubumbashi', Geo::nearest(-11.66, 27.48));
        $this->assertSame('Goma', Geo::nearest(-1.68, 29.23));
        $this->assertNull(Geo::nearest(10.0, 0.0));                     // loin de tout
        $this->assertSame('Kinshasa', Geo::canonical('Ville de Kinshasa'));
    }

    /** Lieu tel que l'enregistre la recherche (/lieux) ; renvoie son identifiant. */
    private function place(string $label, string $level, array $bbox, string $code = 'CD'): int
    {
        return \Illuminate\Support\Facades\DB::table('places')->insertGetId([
            'uid' => 'test:'.$label.':'.$level, 'label' => $label, 'context' => null, 'level' => $level, 'country' => config('places.countries.'.$code),
            'country_code' => $code, 'state' => $level === 'state' ? $label : null, 'bbox' => json_encode($bbox),
            'lat' => ($bbox[0] + $bbox[1]) / 2, 'lng' => ($bbox[2] + $bbox[3]) / 2, 'search_name' => \Illuminate\Support\Str::slug($label),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function versusIn(User $creator, array $extra): CompetitionMatch
    {
        Sanctum::actingAs($creator);
        $this->postJson('/api/competitions', [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Meilleur plat ?', 'category' => 'cuisine',
            'side_a_label' => 'Pondu', 'side_b_label' => 'Moambe', 'match_duration_hours' => 2,
        ] + $extra)->assertCreated();

        return CompetitionMatch::latest('id')->firstOrFail();
    }

    public function test_vote_limited_to_a_city(): void
    {
        // L'organisateur choisit Kinshasa dans la recherche, sans y être (pas de position demandée)
        $creator = User::factory()->create(['city' => 'Mbanza-Ngungu']);
        $m = $this->versusIn($creator, ['geo_place' => [$this->place('Kinshasa', 'city', [-5.08, -3.95, 15.10, 16.55])], 'geo_scope' => 'vote']);
        $c = $m->competition;
        $this->assertSame(['Kinshasa', 'city', 'CD'], [$c->geo_city, $c->geo_level, $c->geo_country_code]);

        $voter = User::factory()->create();
        $this->actingAs($voter);
        // Pas encore localisé : on demande la position
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertStatus(428)->assertJson(['code' => 'location_required']);
        // Position à Goma : refusé
        $this->postJson('/localiser', ['lat' => -1.68, 'lng' => 29.23, 'accuracy' => 20])->assertOk()->assertJson(['city' => 'Goma']);
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertStatus(403)->assertJson(['code' => 'wrong_city']);
        // Position dans Kinshasa (un quartier, peu importe son nom) : accepté
        $this->postJson('/localiser', ['lat' => -4.40, 'lng' => 15.25, 'accuracy' => 20, 'city_hint' => 'Djalo'])->assertOk();
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertOk();
        $this->get($c->url())->assertOk()->assertSee('Réservé à Kinshasa');
        // Une position d'il y a une heure ne suffit plus : on la redemande
        $this->travel(1)->hours();
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_b_id])->assertStatus(428);
    }

    public function test_the_app_sends_its_position_with_the_vote_and_a_bad_zone_is_refused(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        // Zone qui ne vient pas de la recherche : refusée
        $this->postJson('/api/competitions', ['type' => 'duel', 'format' => 'versus', 'title' => 'X ?', 'side_a_label' => 'A', 'side_b_label' => 'B',
            'geo_place' => [999999]])->assertStatus(422);
        $m = $this->versusIn($creator, ['geo_place' => [$this->place('Kongo Central', 'state', [-6.10, -4.30, 12.20, 16.30])], 'geo_scope' => 'vote']);
        $voter = User::factory()->create();
        Sanctum::actingAs($voter);
        // L'app (sans session) envoie lat, lng, accuracy avec le vote ; la province vient de la carte (ici simulée)
        config(['places.geocoder' => 'nominatim']);
        \Illuminate\Support\Facades\Http::fake(['*' => fn ($r) => \Illuminate\Support\Facades\Http::response(['address' => (float) $r['lat'] > -4.6
            ? ['city' => 'Kinshasa', 'state' => 'Kinshasa', 'country_code' => 'cd'] : ['town' => 'Mbanza-Ngungu', 'state' => 'Kongo Central', 'country_code' => 'cd']])]);
        $this->postJson('/api/matches/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id, 'lat' => -4.33, 'lng' => 15.31, 'accuracy' => 15])->assertStatus(403); // Kinshasa : hors Kongo Central (pourtant dans son rectangle)
        $this->postJson('/api/matches/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id, 'lat' => -5.26, 'lng' => 14.87, 'accuracy' => 30000])->assertStatus(422); // imprécise
        $this->postJson('/api/matches/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id, 'lat' => -5.26, 'lng' => 14.87, 'accuracy' => 15])->assertSuccessful(); // Mbanza-Ngungu
    }

    public function test_interests_then_city_are_asked_separately(): void
    {
        $u = User::factory()->create(['interests' => null, 'interests_asked_at' => null, 'city' => null, 'city_asked_at' => null]);
        // D'abord ce qui plaît, et pas la ville en même temps
        $this->actingAs($u)->get('/')->assertOk()->assertSee('Choisis tes disciplines')->assertDontSee('Tu es dans quelle ville');
        $this->post('/interets', ['later' => 1])->assertRedirect();
        $this->get('/')->assertDontSee('Choisis tes disciplines')->assertDontSee('Tu es dans quelle ville'); // pas le même jour
        $this->travel(6)->days();
        $this->get('/')->assertSee('Choisis tes disciplines');
        $this->postJson('/interets', ['interests' => ['eloquence', 'rap']])->assertOk();
        $this->assertSame(['eloquence', 'rap'], $u->fresh()->interests);
        $this->get('/')->assertDontSee('Choisis tes disciplines')->assertDontSee('Tu es dans quelle ville');
        // Le lendemain : la ville, seule
        $this->travel(2)->days();
        $this->get('/')->assertSee('Tu es dans quelle ville')->assertDontSee('Choisis tes disciplines');
        $this->postJson('/ville', ['city' => 'kinshasa'])->assertOk();
        $this->assertSame('Kinshasa', $u->fresh()->city);
        $this->get('/')->assertDontSee('Tu es dans quelle ville');
        $this->get('/parametres/profil')->assertOk()->assertSee('Ce qui te plaît');
    }

    public function test_space_category_and_subcategory(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/espaces', ['name' => 'ISTA Gombe', 'category' => 'education', 'subcategory' => 'institut'])->assertRedirect();
        $s = Space::where('name', 'ISTA Gombe')->firstOrFail();
        $this->assertSame(['education', 'institut', 'school'], [$s->category, $s->subcategory, $s->kind]);
        // Une sous-catégorie d'une autre catégorie est ignorée
        $this->post('/espaces/'.$s->slug.'/modifier', ['name' => 'ISTA Gombe', 'category' => 'arts', 'subcategory' => 'institut'])->assertRedirect();
        $s->refresh();
        $this->assertSame(['arts', null, 'group'], [$s->category, $s->subcategory, $s->kind]);
    }

    public function test_tournament_jury_set_by_the_space(): void
    {
        $owner = User::factory()->create();
        $juror = User::factory()->create();
        $space = Space::create(['owner_id' => $owner->id, 'name' => 'Club Éloquence', 'is_active' => true]);
        $space->addManager($owner, 'admin');
        Sanctum::actingAs($owner);
        $t = $this->postJson('/api/competitions', ['type' => 'tournament', 'cover' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg'), 'title' => 'Joutes', 'space' => $space->slug])->assertCreated()->json('data');
        $c = Competition::where('slug', $t['slug'])->firstOrFail();

        $this->actingAs($owner)->post('/c/'.$c->slug.'/jury', ['jurors_text' => '@'.$juror->username, 'jury_weight' => 70])->assertRedirect();
        $c->refresh();
        $this->assertSame(70, (int) $c->jury_weight);
        $this->assertTrue($c->isJuror($juror));
        $this->post('/c/'.$c->slug.'/jury', ['jurors_text' => '@'.$juror->username, 'jury_weight' => 100])->assertRedirect();
        $this->assertSame('jury', $c->fresh()->vote_audience);
        $this->post('/c/'.$c->slug.'/jury', ['jurors_text' => '', 'jury_weight' => 50])->assertRedirect();
        $this->assertSame(0, (int) $c->fresh()->jury_weight);
        $this->assertSame('public', $c->fresh()->vote_audience);
    }

    public function test_each_space_has_its_own_address(): void
    {
        config(['spaces.domain' => 'tokos.test', 'app.url' => 'https://tokos.test']);
        $owner = User::factory()->create();
        $space = Space::create(['owner_id' => $owner->id, 'name' => 'Les Lions', 'slug' => 'lions', 'is_active' => true, 'category' => 'education', 'subcategory' => 'universite']);

        // L'adresse de l'Espace affiche son petit site (modèle « classique » par défaut), liens vers le site principal
        $this->get('http://lions.tokos.test/')->assertOk()->assertSee('Les Lions')->assertSee('Propulsé par')
            ->assertSee('t-classique', false)->assertSee('https://tokos.test/espaces/lions', false);
        $space->update(['site_template' => 'affiche']);
        $this->get('http://lions.tokos.test/')->assertOk()->assertSee('t-affiche', false);
        $this->get('https://tokos.test/espaces/lions/site?modele=classique')->assertOk()->assertSee('t-classique', false);
        $this->get('http://lions.tokos.test/decouvrir')->assertRedirect('https://tokos.test/decouvrir');
        $this->get('http://inconnu.tokos.test/')->assertRedirect('https://tokos.test/');
        $this->get('http://www.tokos.test/')->assertOk()->assertDontSee('<base href', false); // www = site principal
        $this->get('/espaces/lions')->assertOk()->assertSee('lions.tokos.test')->assertSee('Université');
    }

    public function test_zone_can_be_a_whole_country_and_browser_hint_is_checked(): void
    {
        $u = User::factory()->create();
        // Indice du téléphone cohérent : gardé, avec le pays
        $this->actingAs($u)->postJson('/localiser', ['lat' => -11.66, 'lng' => 27.48, 'city_hint' => 'Lubumbashi', 'country_hint' => 'République démocratique du Congo', 'country_code' => 'cd', 'accuracy' => 20])
            ->assertOk()->assertJson(['city' => 'Lubumbashi', 'country' => 'RD Congo']);
        // Indice incohérent (Kinshasa annoncé, position à Goma) : ignoré
        $this->postJson('/localiser', ['lat' => -1.68, 'lng' => 29.23, 'city_hint' => 'Kinshasa', 'accuracy' => 20])->assertOk()->assertJson(['city' => 'Goma', 'country' => 'RD Congo']);
        // Un quartier noté « village » (Djalo) à Mbanza-Ngungu : c'est la ville qui compte
        $this->postJson('/localiser', ['lat' => -5.26, 'lng' => 14.87, 'city_hint' => 'Djalo', 'country_code' => 'cd', 'accuracy' => 20])->assertOk()->assertJson(['city' => 'Mbanza-Ngungu']);
        $this->postJson('/localiser', ['lat' => -5.26, 'lng' => 14.87, 'city_hint' => 'Mbanza Ngungu', 'accuracy' => 20])->assertOk()->assertJson(['city' => 'Mbanza-Ngungu']);
        // Loin de toute ville connue mais en RD Congo : au moins le pays
        $this->postJson('/localiser', ['lat' => -3.2, 'lng' => 23.6, 'accuracy' => 20])->assertOk()->assertJson(['country' => 'RD Congo']);
        // Au milieu de l'océan : rien
        $this->postJson('/localiser', ['lat' => -30.0, 'lng' => -20.0, 'accuracy' => 20])->assertStatus(422);

        // Compétition réservée au pays : le code pays de la position fait foi
        $m = $this->versusIn($u, ['geo_place' => [$this->place('RD Congo', 'country', [-13.46, 5.39, 12.20, 31.31])], 'geo_scope' => 'vote']);
        $this->assertSame([null, 'RD Congo', 'country'], [$m->competition->geo_city, $m->competition->geo_country, $m->competition->geo_level]);
        $this->actingAs(User::factory()->create())->postJson('/localiser', ['lat' => -4.33, 'lng' => 15.31, 'accuracy' => 20, 'country_code' => 'cd'])->assertOk();
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertOk();
        // Brazzaville : dans le rectangle de la RD Congo, mais un autre pays
        $this->actingAs(User::factory()->create())->postJson('/localiser', ['lat' => -4.26, 'lng' => 15.24, 'accuracy' => 20, 'city_hint' => 'Brazzaville', 'country_code' => 'cg'])->assertOk();
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertStatus(403);
    }

    public function test_place_search_uses_locationiq_when_a_key_is_set(): void
    {
        config(['places.search_provider' => 'locationiq', 'places.locationiq_key' => 'cle']);
        \Illuminate\Support\Facades\Http::fake(['api.locationiq.com/*' => \Illuminate\Support\Facades\Http::response([
            ['display_place' => 'Mbanza-Ngungu', 'type' => 'town', 'lat' => '-5.25', 'lon' => '14.86', 'boundingbox' => ['-5.30', '-5.20', '14.80', '14.92'],
                'address' => ['name' => 'Mbanza-Ngungu', 'state' => 'Kongo Central', 'country' => 'République démocratique du Congo', 'country_code' => 'cd']],
            ['display_place' => 'Kongo Central', 'type' => 'state', 'lat' => '-5.2', 'lon' => '14.2', 'boundingbox' => ['-6.1', '-4.3', '12.2', '16.3'],
                'address' => ['state' => 'Kongo Central', 'country' => 'République démocratique du Congo', 'country_code' => 'cd']],
        ])]);
        $items = $this->actingAs(User::factory()->create())->getJson('/lieux?q=mbanza')->assertOk()->json('items');
        $this->assertSame(['Mbanza-Ngungu', 'Ville', 'Kongo Central, RD Congo'], [$items[0]['label'], $items[0]['level_label'], $items[0]['context']]);
        $this->assertSame('Province', $items[1]['level_label']);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'key=cle'));
        // Le jeton est accepté à la création
        $this->post('/creer', ['type' => 'challenge', 'content' => 'Slam de Mbanza', 'geo_place' => [$items[0]['id'], $items[1]['id']]])->assertRedirect();
        $c = Competition::latest('id')->firstOrFail();
        $this->assertSame(['Mbanza-Ngungu', 'Kongo Central'], array_column($c->geo_zones, 'label'));
        $this->assertSame('Mbanza-Ngungu ou Kongo Central', Geo::zone($c));

        // Économies : la même recherche, puis un début de nom déjà connu, ne rappellent pas LocationIQ
        $this->getJson('/lieux?q=mbanza')->assertOk();
        \Illuminate\Support\Facades\DB::table('places')->insert(collect(['Mbandaka', 'Mbankana'])->map(fn ($n) => ['uid' => 'x:'.$n, 'label' => $n, 'level' => 'city',
            'bbox' => '[0,0,0,0]', 'lat' => 0, 'lng' => 0, 'search_name' => \Illuminate\Support\Str::slug($n), 'created_at' => now(), 'updated_at' => now()])->all());
        $this->getJson('/lieux?q=mban')->assertOk()->assertJsonCount(3, 'items');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_several_zones_and_reverse_geocoding_is_kept(): void
    {
        $creator = User::factory()->create();
        $m = $this->versusIn($creator, ['geo_place' => [$this->place('Kinshasa', 'city', [-5.08, -3.95, 15.10, 16.55]), $this->place('Mbanza-Ngungu', 'city', [-5.30, -5.20, 14.80, 14.92])], 'geo_scope' => 'vote']);
        $this->assertSame('Réservé aux personnes à Kinshasa ou Mbanza-Ngungu. Ta position est en dehors.', (function () use ($m) {
            $this->actingAs(User::factory()->create())->postJson('/localiser', ['lat' => -1.68, 'lng' => 29.23, 'accuracy' => 20])->assertOk(); // Goma
            return $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertStatus(403)->json('message');
        })());
        $this->actingAs(User::factory()->create())->postJson('/localiser', ['lat' => -5.25, 'lng' => 14.86, 'accuracy' => 20])->assertOk(); // Mbanza-Ngungu
        $this->postJson('/m/'.$m->id.'/vote', ['entry_id' => $m->entry_a_id])->assertOk();

        // Position déjà traduite dans ce carré d'1 km : le service de cartes n'est pas rappelé
        config(['places.geocoder' => 'nominatim']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['address' => ['town' => 'Kimpese', 'state' => 'Kongo Central', 'country_code' => 'cd']])]);
        $this->postJson('/localiser', ['lat' => -5.556, 'lng' => 14.433, 'accuracy' => 20])->assertOk();
        $this->postJson('/localiser', ['lat' => -5.557, 'lng' => 14.434, 'accuracy' => 20])->assertOk();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_a_position_from_the_internet_connection_is_refused(): void
    {
        $u = User::factory()->create();
        // Ordinateur sans GPS : position à 30 km près, devinée d'après la connexion → refusée
        $this->actingAs($u)->postJson('/localiser', ['lat' => -4.33, 'lng' => 15.31, 'accuracy' => 30000])
            ->assertStatus(422)->assertJson(['code' => 'imprecise'])
            ->assertJsonPath('message', fn ($m) => str_contains($m, '30 km') && str_contains($m, 'Active la localisation'));
        $this->assertNull($u->fresh()->city_located_at);
        // Sans marge d'erreur : refusé aussi
        $this->postJson('/localiser', ['lat' => -4.33, 'lng' => 15.31])->assertStatus(422);
        // GPS : accepté
        $this->postJson('/localiser', ['lat' => -5.26, 'lng' => 14.87, 'accuracy' => 15])->assertOk()->assertJson(['city' => 'Mbanza-Ngungu']);
    }
}
