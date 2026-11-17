<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nettoyage des codes SMS expirés
Schedule::command('verification:clean')->everyTenMinutes();

// ⭐ Moteur de compétition : clôtures de duels, lancements, rappels (chaque minute)
// En production, une seule entrée cron suffit :  * * * * * php /chemin/artisan schedule:run
// En local :  php artisan schedule:work
Schedule::command('competitions:tick')->everyMinute()->withoutOverlapping();

// Ajoute largeur / hauteur aux vidéos déjà publiées (pour les afficher dans leur format dès le départ)
Artisan::command('media:dimensions', function () {
    $fill = function (?array $media) use (&$changed) {
        foreach ((array) $media as $i => $m) {
            if (($m['type'] ?? '') !== 'video' || ! empty($m['metadata']['width']) || empty($m['url']) || filter_var($m['url'], FILTER_VALIDATE_URL)) {
                continue;
            }
            $path = storage_path('app/public/'.$m['url']);
            if (is_file($path) && ($dims = \App\Support\MediaDuration::dimensions($path))) {
                $media[$i]['metadata'] = array_merge($m['metadata'] ?? [], $dims);
                $changed = true;
            }
        }

        return $media;
    };
    $n = 0;
    foreach ([\App\Models\CompetitionEntry::class, \App\Models\Post::class] as $class) {
        $class::query()->whereNotNull('media')->chunkById(200, function ($rows) use ($fill, &$n, &$changed) {
            foreach ($rows as $row) {
                $changed = false;
                $media = $fill(is_array($row->media) ? $row->media : json_decode((string) $row->media, true));
                if ($changed) {
                    $row->forceFill(['media' => $media])->saveQuietly();
                    $n++;
                }
            }
        });
    }
    $this->info($n.' publication(s) mises à jour.');
})->purpose('Largeur et hauteur des vidéos déjà publiées');

// Valider à la main un achat de coins en attente (quand le paiement a été reçu hors du site)
Artisan::command('coins:valider {id}', function (int $id) {
    $p = \App\Models\CoinPurchase::findOrFail($id);
    app(\App\Services\Coins\CoinService::class)->completePurchase($p, 'manuel');
    $this->info($p->coins.' coins crédités à '.$p->user->username.'.');
})->purpose('Créditer un achat de coins en attente');

// Retraits : marquer comme versé, ou refuser (les coins reviennent)
Artisan::command('coins:retraits', function () {
    $rows = \App\Models\CoinWithdrawal::with('user')->where('status', 'pending')->oldest()->get();
    $this->table(['id', 'utilisateur', 'coins', 'montant', 'opérateur', 'numéro', 'date'], $rows->map(fn ($w) => [
        $w->id, $w->user?->username, $w->coins, $w->amount.' '.$w->currency, $w->operator, $w->phone, $w->created_at->format('d/m H:i'),
    ]));
})->purpose('Lister les retraits en attente');

Artisan::command('coins:verser {id} {--ref=} {--refuser=}', function (int $id) {
    $w = \App\Models\CoinWithdrawal::findOrFail($id);
    $svc = app(\App\Services\Coins\CoinService::class);
    if ($this->option('refuser') !== null) {
        $svc->rejectWithdrawal($w, $this->option('refuser') ?: null);
        $this->info('Retrait refusé, coins rendus.');
    } else {
        $svc->markWithdrawalPaid($w, $this->option('ref'));
        $this->info('Retrait marqué comme versé.');
    }
})->purpose('Marquer un retrait comme versé (ou --refuser="raison")');

// Donner (ou retirer) l'accès à l'admin : php artisan admin:promote 810000000 [--retirer]
Artisan::command('admin:promote {phone} {--retirer}', function (string $phone) {
    $number = ltrim(preg_replace('/\D/', '', $phone), '0');
    $user = \App\Models\User::where('phone_number', $number)->orWhere('username', ltrim($phone, '@'))->first();
    if (! $user) {
        $this->error('Aucun compte avec ce numéro ou ce pseudo.');

        return 1;
    }
    $user->forceFill(['is_admin' => ! $this->option('retirer')])->save();
    $this->info($user->first_name.' '.$user->last_name.($this->option('retirer') ? ' n\'est plus admin.' : ' est maintenant admin : /admin'));
})->purpose('Donner ou retirer l\'accès à l\'administration');

// Supprimer les pièces des demandes de certification traitées (tous les jours)
Artisan::command('certification:purge', function () {
    $this->info(app(\App\Services\CertificationService::class)->purge().' demande(s) nettoyée(s).');
})->purpose('Supprimer les pièces des certifications traitées');
\Illuminate\Support\Facades\Schedule::command('certification:purge')->dailyAt('03:10');

// Concours en cours : un classement provisoire dans le fil (au plus un par jour et par concours)
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\FeedCards::postStandings())->name('contest-standings')->hourly()->withoutOverlapping();

// Copie des médias lancée depuis l'admin (Stockage des médias) : continue en arrière-plan, un peu chaque minute
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\MediaStorage::step(15))->name('media-copy')->everyMinute()
    ->when(fn () => ! empty(\App\Support\MediaStorage::settings()['copy']['running']))->withoutOverlapping();

// Copier les médias du serveur vers le stockage externe (ex. R2) avant de passer MEDIA_DISK=r2
Artisan::command('media:migrer {disk=r2} {--dossier=}', function (string $disk) {
    $from = \Illuminate\Support\Facades\Storage::disk('public');
    $to = \Illuminate\Support\Facades\Storage::disk($disk);
    $files = $from->allFiles((string) $this->option('dossier'));
    $bar = $this->output->createProgressBar(count($files));
    $copied = 0;
    foreach ($files as $file) {
        if (str_starts_with($file, 'og/') || str_starts_with(basename($file), '.')) {
            $bar->advance();
            continue;
        }
        if (! $to->exists($file)) {
            $to->writeStream($file, $from->readStream($file), ['visibility' => 'public']);
            $copied++;
        }
        $bar->advance();
    }
    $bar->finish();
    $this->newLine();
    $this->info($copied.' fichier(s) copié(s) vers '.$disk.'. Mets ensuite MEDIA_DISK='.$disk.' dans .env.');
})->purpose('Copier les médias vers un autre disque (R2)');

// Annonces de l'admin programmées ou mensuelles (Communauté → Annonces)
\Illuminate\Support\Facades\Schedule::job(new \App\Jobs\SendDueAnnouncements)->name('announcements')->everyFiveMinutes();

// Le fil se remplit tout seul : « X repasse premier », « duel serré », « dernières heures »…
// et chaque participant d'un concours sait où il en est (Fonctions : publications automatiques, alertes de classement)
\Illuminate\Support\Facades\Schedule::job(new \App\Jobs\RunPulse)->name('pulse')->everyTenMinutes();
Artisan::command('pulse:run', function () {
    $s = app(\App\Services\Social\Pulse::class)->run();
    $this->info($s['posts'].' publication(s), '.$s['alerts'].' alerte(s), '.$s['reminders'].' rappel(s).');
})->purpose('Publications automatiques du fil et alertes de classement (tourne seul toutes les 10 minutes)');

// ⭐ File d'attente : les envois lourds (push, notifications à beaucoup de monde, annonces, relances, fil automatique)
// passent par la table « jobs » et sont traités ici, chaque minute, jusqu'à ce que la file soit vide.
// Avec un vrai cron (Hostinger : * * * * * php artisan schedule:run), ou en local avec « php artisan schedule:work ».
// Quand c'est l'adresse secrète (cron-job.org) qui lance tout, elle vide déjà la file elle-même (CronRunner).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
    ->name('queue')->everyMinute()->withoutOverlapping(5)->runInBackground()
    ->when(fn () => ! app()->bound('cron.http'));

// Santé du cron et de la file : php artisan cron:etat
Artisan::command('cron:etat', function () {
    $s = \App\Support\CronRunner::settings();
    $pending = \App\Support\CronRunner::pendingJobs();
    $failed = \Illuminate\Support\Facades\Schema::hasTable('failed_jobs') ? \DB::table('failed_jobs')->count() : 0;
    $last = \Illuminate\Support\Facades\Cache::get('schedule:last_run');
    $this->line('Dernier passage du planificateur : '.($last ? \Illuminate\Support\Carbon::parse($last)->diffForHumans() : 'jamais (le cron ne tourne pas ?)'));
    $this->line('Dernier appel de l\'adresse secrète : '.(! empty($s['last_run_at']) ? \Illuminate\Support\Carbon::parse($s['last_run_at'])->diffForHumans() : 'jamais'));
    $this->line('Tâches en attente dans la file : '.$pending.($pending > 50 ? '  ← la file n\'est pas vidée : le cron tourne-t-il ?' : ''));
    $this->line('Tâches en échec : '.$failed.($failed ? '  (php artisan queue:failed pour voir, queue:retry all pour relancer)' : ''));
})->purpose('Le cron et la file d\'attente tournent-ils ?');

// Repère du dernier passage du planificateur (pour cron:etat et l'admin)
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever('schedule:last_run', now()->toIso8601String()))->name('heartbeat')->everyMinute();
