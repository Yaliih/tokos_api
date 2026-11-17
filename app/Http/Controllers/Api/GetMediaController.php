<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MediaStorage;

class GetMediaController extends Controller
{
    /**
     * Sert un média. Le fichier est cherché sur le serveur (storage/app/public) ; s'il n'y est pas
     * et que Cloudflare R2 est branché, on renvoie vers R2. Ainsi rien ne casse pendant une copie,
     * ni quand on bascule d'un stockage à l'autre.
     *
     * Sécurité : le chemin demandé doit rester dans storage/app/public (pas de « .. » pour lire le .env).
     */
    public function file($path)
    {
        $path = preg_replace('/,(?=[^,]*$)/', '.', str_replace('&', '/', $path));
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            abort(404);
        }

        $root = realpath(storage_path('app/public'));
        $full = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;
        if ($root && $full && str_starts_with($full, $root.DIRECTORY_SEPARATOR) && is_file($full)) {
            return response()->file($full, ['Cache-Control' => 'public, max-age=604800']);
        }

        // Pas sur le serveur : chez R2 (lien temporaire de redirection, pas « définitif », on peut changer d'avis)
        if ($url = MediaStorage::r2Url($path)) {
            return redirect()->away($url, 302)->header('Cache-Control', 'public, max-age=3600');
        }

        abort(404);
    }
}
