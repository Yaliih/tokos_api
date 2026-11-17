<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Où ranger les photos, vidéos et audios : sur le serveur (« public ») ou chez Cloudflare R2.
 * Tout se règle dans l'admin (page « Stockage des médias »), les valeurs du .env servent de départ.
 *
 * - Copier : envoie les fichiers existants d'un stockage à l'autre, petit à petit (depuis la page admin
 *   ou par la tâche planifiée), sans jamais rien supprimer.
 * - Basculer : les nouveaux fichiers vont dans le stockage choisi. Les anciens restent lisibles pendant la copie :
 *   /api/get-media sert le fichier du serveur s'il y est, sinon renvoie vers R2.
 * - Liens directs vers R2 seulement quand tout a été copié (plus rapide, moins de charge pour le serveur).
 */
class MediaStorage
{
    private const KEY = 'storage';

    private const SKIP = ['og/', 'tokos-test/'];

    /** Réglages enregistrés (le secret R2 reste chiffré dans la base). */
    public static function settings(): array
    {
        $saved = Cache::rememberForever('settings.'.self::KEY, function () {
            try {
                return Schema::hasTable('settings') ? (Setting::find(self::KEY)?->value ?? []) : [];
            } catch (\Throwable) {
                return [];
            }
        });

        return array_replace_recursive([
            'disk' => env('MEDIA_DISK', 'public'),
            'synced' => env('MEDIA_DISK', 'public') !== 'public',
            'r2' => [],
            'copy' => ['running' => false],
        ], $saved);
    }

    public static function save(array $values): void
    {
        $all = array_replace_recursive(self::settings(), $values);
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $all]);
        Cache::forget('settings.'.self::KEY);
        self::apply();
    }

    /** Branche les réglages de l'admin dans la configuration (appelé au démarrage). */
    public static function apply(): void
    {
        $s = self::settings();
        $r2 = $s['r2'] ?? [];
        foreach (['endpoint' => 'endpoint', 'bucket' => 'bucket', 'key' => 'key', 'url' => 'url'] as $from => $to) {
            if (filled($r2[$from] ?? null)) {
                config(['filesystems.disks.r2.'.$to => $r2[$from]]);
            }
        }
        if (filled($r2['secret'] ?? null)) {
            try {
                config(['filesystems.disks.r2.secret' => Crypt::decryptString($r2['secret'])]);
            } catch (\Throwable) {
            }
        }
        $disk = $s['disk'] === 'r2' && self::ready() ? 'r2' : 'public';
        config(['filesystems.media' => $disk]);
    }

    /** Le module S3 (pour R2) est-il installé ? composer require league/flysystem-aws-s3-v3 */
    public static function adapterInstalled(): bool
    {
        return class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
    }

    public static function configured(): bool
    {
        $d = config('filesystems.disks.r2');

        return filled($d['endpoint'] ?? null) && filled($d['bucket'] ?? null) && filled($d['key'] ?? null) && filled($d['secret'] ?? null);
    }

    /** R2 utilisable : module installé et clés remplies. */
    public static function ready(): bool
    {
        return self::adapterInstalled() && self::configured();
    }

    public static function current(): string
    {
        return config('filesystems.media', 'public');
    }

    /** Liens directs vers R2 (sans passer par le serveur) : seulement quand tous les fichiers y sont. */
    public static function directUrls(): bool
    {
        return self::current() === 'r2' && ! empty(self::settings()['synced']) && filled(config('filesystems.disks.r2.url'));
    }

    /** Lien public d'un fichier chez R2, ou null. */
    public static function r2Url(string $path): ?string
    {
        $base = config('filesystems.disks.r2.url');

        return self::ready() && filled($base) ? rtrim($base, '/').'/'.ltrim($path, '/') : null;
    }

    /** Écrit, relit et supprime un petit fichier chez R2. @return array{ok: bool, message: string} */
    public static function test(): array
    {
        if (! self::adapterInstalled()) {
            return ['ok' => false, 'message' => __('Module R2 absent. Sur le serveur : composer require league/flysystem-aws-s3-v3')];
        }
        if (! self::configured()) {
            return ['ok' => false, 'message' => 'Remplis le point d\'accès, le bucket et les deux clés.'];
        }
        try {
            $disk = Storage::disk('r2');
            $file = 'tokos-test/ping-'.uniqid().'.txt';
            $disk->put($file, 'ok', ['visibility' => 'public']);
            $back = $disk->get($file);
            $disk->delete($file);

            return $back === 'ok'
                ? ['ok' => true, 'message' => 'Connexion à R2 réussie : écriture, lecture et suppression fonctionnent.']
                : ['ok' => false, 'message' => 'R2 répond, mais le fichier relu est différent.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'R2 refuse : '.mb_substr($e->getMessage(), 0, 220)];
        }
    }

    /** Lance la copie : « to_r2 » (serveur → R2) ou « to_local » (R2 → serveur). */
    public static function startCopy(string $direction): void
    {
        [$from] = self::disks($direction);
        $total = count(self::files($from));
        self::save(['copy' => ['running' => true, 'direction' => $direction, 'cursor' => 0, 'total' => $total, 'copied' => 0,
            'errors' => 0, 'last_error' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => null]]);
    }

    public static function stopCopy(): void
    {
        self::save(['copy' => ['running' => false]]);
    }

    /** Copie pendant quelques secondes au plus, puis s'arrête ; on reprend au même endroit la fois suivante. */
    public static function step(int $seconds = 15): array
    {
        $c = self::settings()['copy'];
        if (empty($c['running'])) {
            return $c;
        }
        $lock = Cache::lock('media-copy', $seconds + 30);
        if (! $lock->get()) {
            return $c;
        }
        try {
            @set_time_limit($seconds + 60);
            [$from, $to] = self::disks($c['direction']);
            $src = Storage::disk($from);
            $dst = Storage::disk($to);
            $files = self::files($from);
            $c['total'] = count($files);
            $until = microtime(true) + $seconds;
            $i = (int) $c['cursor'];
            while ($i < $c['total'] && microtime(true) < $until) {
                $file = $files[$i];
                try {
                    if (! $dst->exists($file)) {
                        $stream = $src->readStream($file);
                        $dst->writeStream($file, $stream, ['visibility' => 'public']);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        $c['copied']++;
                    }
                } catch (\Throwable $e) {
                    $c['errors']++;
                    $c['last_error'] = $file.' : '.mb_substr($e->getMessage(), 0, 160);
                    Log::warning('Copie des médias : '.$c['last_error']);
                }
                $i++;
            }
            $c['cursor'] = $i;
            $values = ['copy' => $c];
            if ($i >= $c['total']) {
                $c['running'] = false;
                $c['finished_at'] = now()->toIso8601String();
                $values['copy'] = $c;
                // Tout est chez R2 et c'est le stockage actif : liens directs possibles
                $values['synced'] = $c['direction'] === 'to_r2' && $c['errors'] === 0;
            }
            self::save($values);

            return $c;
        } finally {
            $lock->release();
        }
    }

    /** Choisit le stockage des nouveaux fichiers. */
    public static function switchTo(string $disk): void
    {
        $s = self::settings();
        $synced = $disk === 'r2' && ($s['copy']['direction'] ?? null) === 'to_r2' && ! empty($s['copy']['finished_at']) && (int) ($s['copy']['errors'] ?? 0) === 0;
        self::save(['disk' => $disk === 'r2' ? 'r2' : 'public', 'synced' => $synced]);
    }

    /** @return array{0: string, 1: string} disque source, disque destination */
    private static function disks(string $direction): array
    {
        return $direction === 'to_local' ? ['r2', 'public'] : ['public', 'r2'];
    }

    /** @return string[] */
    private static function files(string $disk): array
    {
        $files = array_values(array_filter(Storage::disk($disk)->allFiles(), function ($f) {
            foreach (self::SKIP as $p) {
                if (str_starts_with($f, $p)) {
                    return false;
                }
            }

            return ! str_starts_with(basename($f), '.');
        }));
        sort($files);

        return $files;
    }
}
