<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les disciplines vivent dans la base, réglées dans l'admin (Réglages → Disciplines), plus dans le code.
 * Cette migration pose la liste de départ une seule fois :
 *   - « categories »         : la liste utilisée partout (l'admin la modifie) ;
 *   - « categories_default » : la liste de départ, pour le bouton « Remettre la liste par défaut ».
 * Une liste déjà réglée dans l'admin n'est pas touchée.
 */
return new class extends Migration
{
    private const START = [
        'chant' => 'Chant & musique',
        'rap' => 'Rap & freestyle',
        'danse' => 'Danse',
        'humour' => 'Humour',
        'dessin' => 'Art & photo',
        'mode' => 'Mode & beauté',
        'cuisine' => 'Cuisine',
        'sport' => 'Sport',
        'gaming' => 'Jeux vidéo',
        'sciences' => 'Sciences',
        'tech' => 'Technologie & code',
        'business' => 'Entrepreneuriat',
        'eloquence' => 'Éloquence & débat',
        'poesie' => 'Poésie & slam',
        'savoir' => 'Quiz & culture',
        'autre' => 'Autre',
    ];

    public function up(): void
    {
        $rows = collect(self::START)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'active' => true])->values()->all();
        $now = now();
        foreach (['categories_default', 'categories'] as $key) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'categories_default')->delete();
    }
};
