<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Les tâches automatiques lancées par un service externe (cron-job.org) qui appelle une adresse secrète chaque minute :
 * 1. php artisan schedule:run (fin des duels, tours de tournoi, nettoyages, copie des médias…) ;
 * 2. php artisan queue:work jusqu'à ce que la file soit vide (notifications, envois…).
 * cron-job.org coupe l'appel après 30 secondes : le travail continue quand même côté serveur.
 */
class CronRunner
{
    private const KEY = 'cron';

    public static function settings(): array
    {
        return Setting::find(self::KEY)?->value ?? [];
    }

    private static function put(array $values): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => array_replace(self::settings(), $values)]);
    }

    /** Le code secret de l'adresse (créé au premier besoin). CRON_TOKEN dans .env le remplace. */
    public static function token(): string
    {
        if (filled(env('CRON_TOKEN'))) {
            return (string) env('CRON_TOKEN');
        }
        $t = self::settings()['token'] ?? null;
        if (! $t) {
            $t = Str::random(40);
            self::put(['token' => $t]);
        }

        return $t;
    }

    public static function regenerate(): string
    {
        $t = Str::random(40);
        self::put(['token' => $t]);

        return $t;
    }

    public static function url(): string
    {
        return rtrim(config('app.url'), '/').'/api/cron/'.self::token();
    }

    public static function check(string $token): bool
    {
        return hash_equals(self::token(), $token);
    }

    /** @return array{ran: bool, ms: int, jobs: int} */
    public static function run(): array
    {
        $lock = Cache::lock('cron-http', 55);
        if (! $lock->get()) {
            return ['ran' => false, 'ms' => 0, 'jobs' => 0]; // le passage précédent tourne encore
        }
        $t = microtime(true);
        $error = null;
        $jobs = 0;
        try {
            @set_time_limit(90);
            app()->instance('cron.http', true); // la file est vidée juste après, pas besoin d'un 2e « queue:work »
            try {
                Artisan::call('schedule:run');
            } catch (\Throwable $e) {
                // Une tâche planifiée qui échoue ne doit pas empêcher la file d'attente de se vider
                $error = 'planificateur : '.mb_substr($e->getMessage(), 0, 200);
                report($e);
            }
            $before = self::pendingJobs();
            Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 20, '--tries' => 3, '--quiet' => true]);
            $jobs = max(0, $before - self::pendingJobs());
        } catch (\Throwable $e) {
            $error = ($error ? $error.' / ' : '').mb_substr($e->getMessage(), 0, 300);
            report($e);
        } finally {
            $lock->release();
        }
        $ms = (int) round((microtime(true) - $t) * 1000);
        $s = self::settings();
        self::put(['last_run_at' => now()->toIso8601String(), 'last_ms' => $ms, 'last_jobs' => $jobs, 'last_error' => $error, 'runs' => (int) ($s['runs'] ?? 0) + 1]);

        return ['ran' => true, 'ms' => $ms, 'jobs' => $jobs];
    }

    public static function pendingJobs(): int
    {
        try {
            return config('queue.default') === 'database' ? (int) \DB::table(config('queue.connections.database.table', 'jobs'))->count() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
