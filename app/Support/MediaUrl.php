<?php

namespace App\Support;

/**
 * Construit les URLs de médias au format déjà utilisé par l'app
 * (route "media" : "/" remplacé par "&", dernier "." remplacé par ",").
 */
class MediaUrl
{
    public static function make(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Médias chez Cloudflare R2, tous copiés : lien direct. Sinon on passe par le serveur,
        // qui sert le fichier s'il l'a encore, ou renvoie vers R2.
        if (MediaStorage::directUrls()) {
            return rtrim(config('filesystems.disks.r2.url'), '/').'/'.ltrim($path, '/');
        }

        return route('media', preg_replace('/\.(?=[^.]*$)/', ',', str_replace('/', '&', $path)));
    }

    /**
     * Transforme un tableau de médias stockés ([type, url, metadata]) en médias avec URL publique.
     */
    public static function list(?array $media): array
    {
        if (empty($media)) {
            return [];
        }

        return collect($media)
            ->filter(fn ($m) => is_array($m) && ! empty($m['url']))
            ->map(fn ($m) => array_filter([
                'type' => $m['type'] ?? 'image',
                'url' => self::make($m['url']),
                'thumbnail_url' => isset($m['thumbnail']) ? self::make($m['thumbnail']) : null,
                'metadata' => $m['metadata'] ?? null,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }
}
