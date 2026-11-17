<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\Space;
use App\Models\User;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\MatchService;
use App\Services\Competition\VoteService;
use App\Services\Social\SocialHooks;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration pour tester le site et l'app en local.
 *   php artisan db:seed --class=DemoSeeder
 * Comptes : +243 810000001 à +243 810000010, mot de passe "password".
 * ⚠️ Ne pas lancer en production.
 */
class DemoSeeder extends Seeder
{
    public function run(CompetitionService $competitions, VoteService $votes, MatchService $matches, SocialHooks $hooks): void
    {
        $names = [
            ['Grace', 'Mbuyi'], ['Junior', 'Kabasele'], ['Merveille', 'Lukusa'], ['Patrick', 'Ilunga'], ['Ruth', 'Ngalula'],
            ['Daniel', 'Tshibangu'], ['Sarah', 'Mutombo'], ['Christian', 'Kalala'], ['Esther', 'Mwamba'], ['Fiston', 'Banza'],
        ];
        $users = collect($names)->map(fn ($n, $i) => User::firstOrCreate(
            ['phone_code' => '+243', 'phone_number' => (string) (810000001 + $i)],
            ['first_name' => $n[0], 'last_name' => $n[1], 'password' => Hash::make('password'), 'birth_date' => '1999-01-01',
                'gender' => $i % 2 ? 'male' : 'female', 'phone_verified_at' => now(), 'created_at' => now()->subMonths(2)]
        ));

        // Abonnements croisés
        foreach ($users as $i => $u) {
            foreach ($users->slice($i + 1, 4) as $other) {
                $u->follow($other);
                $hooks->followed($u, $other);
            }
        }

        $lions = Space::firstOrCreate(['name' => 'Lycée Boboto'], ['owner_id' => $users[0]->id, 'bio' => 'Les talents du Lycée Boboto.', 'category' => 'rap', 'is_active' => true]);
        $aigles = Space::firstOrCreate(['name' => 'Crew Matonge'], ['owner_id' => $users[1]->id, 'bio' => 'Danse et freestyle depuis Matonge.', 'category' => 'danse', 'is_active' => true]);
        $lions->addManager($users[0], 'admin');
        $aigles->addManager($users[1], 'admin');
        foreach ($users->slice(2, 3) as $u) {
            $lions->addMember($u);
            $hooks->joinedSpace($u, $lions);
        }
        foreach ($users->slice(5, 3) as $u) {
            $aigles->addMember($u);
            $hooks->joinedSpace($u, $aigles);
        }

        // Un défi avec plusieurs challengers
        $challenge = $competitions->create($users[2], [
            'type' => 'challenge', 'title' => 'Freestyle de 30 secondes sur "Kinshasa la belle"', 'category' => 'rap',
            'content' => "Kin la belle, ville qui ne dort jamais,\nmes rimes descendent le boulevard comme un taxi-bus pressé.",
            'match_duration_hours' => 24,
        ]);
        foreach ([$users[3], $users[6], $users[8]] as $u) {
            $competitions->submitEntry($u, $challenge, ['content' => 'Ma réponse : je prends le beat et je le ramène à Lemba !']);
        }

        // Un versus
        $competitions->create($users[4], [
            'type' => 'duel', 'format' => 'versus', 'title' => 'Le meilleur plat du dimanche', 'category' => 'cuisine',
            'side_a_label' => 'Pondu au poisson salé', 'side_b_label' => 'Moambe de poulet', 'match_duration_hours' => 48,
        ]);

        // Duel entre deux Espaces
        $duel = $competitions->create($users[1], [
            'type' => 'duel', 'format' => 'challenge', 'title' => 'Battle de danse : Matonge contre Boboto', 'category' => 'danse',
            'space' => $aigles->slug, 'opponent_type' => 'space', 'opponent' => $lions->slug,
            'content' => 'Notre chorégraphie ndombolo revisitée.', 'member_vote_policy' => 'excluded',
        ]);
        $inv = $duel->invitations()->first();
        $competitions->acceptInvitation($users[0], $inv, ['content' => 'Réponse du Lycée Boboto : coupé-décalé et afro-dance.']);

        // Un tournoi déjà lancé
        $tournament = $competitions->create($users[5], [
            'type' => 'tournament', 'title' => 'Coupe du rire de Kin', 'cover' => self::demoCover(), 'category' => 'humour', 'match_duration_hours' => 24,
        ]);
        foreach ($users->except([5])->take(6) as $u) {
            $competitions->submitEntry($u, $tournament, ['content' => 'Ma meilleure blague sur les embouteillages de Kin.']);
        }
        $competitions->startNow($tournament->fresh());

        // Un concours d'Espace
        $contest = $competitions->create($users[0], [
            'type' => 'contest', 'title' => 'Plus belle voix du lycée', 'category' => 'chant', 'space' => $lions->slug,
            'votes_per_user' => 2, 'participation_mode' => 'individual',
            'description' => 'Une chanson, 1 minute, le public vote. Les trois meilleures voix chantent à la fête de fin d\'année.',
            'cover' => self::demoCover(),
        ]);
        foreach ($users->slice(2, 4) as $u) {
            $competitions->submitEntry($u, $contest, ['content' => 'J\'interprète « Indépendance Cha-Cha ».']);
        }
        $competitions->startNow($contest->fresh());

        // Des votes partout
        foreach (CompetitionMatch::where('status', 'voting')->get() as $m) {
            foreach ($users->shuffle()->take(6) as $u) {
                try {
                    $votes->voteMatch($u, $m, random_int(0, 2) ? $m->entry_a_id : $m->entry_b_id);
                } catch (\Throwable) {
                    // participant ou membre exclu : normal
                }
            }
        }
        foreach ($contest->fresh()->entries as $e) {
            foreach ($users->shuffle()->take(3) as $u) {
                try {
                    $votes->voteContestEntry($u, $contest->fresh(), $e);
                } catch (\Throwable) {
                }
            }
        }

        // On termine un duel pour avoir des résultats, classements et badges
        $first = $challenge->matches()->first();
        $first->update(['ends_at' => now()->subMinute()]);
        $matches->close($first->fresh());
    }

    /** Petite image de couverture générée (pas de fichier à fournir pour la démo). */
    private static function demoCover(): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cover').'.png';
        $img = imagecreatetruecolor(1200, 630);
        imagefill($img, 0, 0, imagecolorallocate($img, 108, 92, 231));
        imagepng($img, $path);
        imagedestroy($img);

        return new \Illuminate\Http\UploadedFile($path, 'cover.png', 'image/png', null, true);
    }
}
