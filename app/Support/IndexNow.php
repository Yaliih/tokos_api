<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * IndexNow : prévient Bing (et Yandex, Seznam, Naver) qu'une page vient de naître ou de changer.
 * Le fichier public/<clé>.txt prouve que le site est bien le propriétaire.
 */
class IndexNow
{
    public const KEY = 'sosa9f3c1a7b2e';

    /** @param  array<int, string>  $urls */
    public static function ping(array $urls): bool
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (! $urls) {
            return false;
        }
        try {
            $response = Http::timeout(5)->acceptJson()->post('https://api.indexnow.org/indexnow', [
                'host' => parse_url(config('app.url'), PHP_URL_HOST),
                'key' => self::KEY,
                'keyLocation' => rtrim(config('app.url'), '/').'/'.self::KEY.'.txt',
                'urlList' => array_slice($urls, 0, 10000),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}