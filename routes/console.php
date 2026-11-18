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
// Créer un compte admin en tapant le numéro et le mot de passe : php artisan admin:creer
// Sans option, la commande pose les questions ; avec options, elle peut tourner sans intervention :
//   php artisan admin:creer --telephone=810000000 --mot-de-passe=MonMotDePasse
Artisan::command('admin:creer {--telephone=} {--mot-de-passe=} {--code=} {--prenom=} {--nom=} {--naissance=}', function () {
    $interactif = $this->input->isInteractive();

    // Indicatif du pays : +243 par défaut
    $code = '+'.ltrim((string) ($this->option('code') ?: '+243'), '+');

    // Le numéro, sans le 0 et sans espaces
    $saisi = (string) $this->option('telephone');
    if ($saisi === '' && $interactif) {
        $saisi = (string) $this->ask('Numéro de téléphone (sans le 0, exemple 810000000)');
    }
    $number = ltrim((string) preg_replace('/\D/', '', $saisi), '0');
    if ($number === '' || strlen($number) < 6 || strlen($number) > 20) {
        $this->error('Numéro invalide. Exemple : php artisan admin:creer --telephone=810000000');

        return 1;
    }

    // Le mot de passe, saisi sans l'afficher à l'écran
    $mdp = (string) $this->option('mot-de-passe');
    if ($mdp === '' && $interactif) {
        $mdp = (string) $this->secret('Mot de passe (6 caractères minimum)');
        if ($mdp !== '' && (string) $this->secret('Confirmer le mot de passe') !== $mdp) {
            $this->error('Les deux mots de passe ne sont pas identiques.');

            return 1;
        }
    }
    if (strlen($mdp) < 6) {
        $this->error('Mot de passe trop court : 6 caractères minimum.');

        return 1;
    }

    // Nom affiché et date de naissance (obligatoire en base, sans importance pour un admin)
    $prenom = (string) ($this->option('prenom') ?: 'Admin');
    $nom = (string) ($this->option('nom') ?: 'Sosa');
    $naissance = (string) ($this->option('naissance') ?: '1990-01-01');

    $user = \App\Models\User::where('phone_number', $number)->first();
    $existe = (bool) $user;

    if (! $user) {
        $user = new \App\Models\User();
        $user->first_name = $prenom;
        $user->last_name = $nom;
        $user->phone_code = $code;
        $user->phone_number = $number;
        $user->birth_date = $naissance;
    }
    $user->password = \Illuminate\Support\Facades\Hash::make($mdp);
    $user->is_admin = true;
    $user->is_active = true;
    $user->phone_verified_at = $user->phone_verified_at ?: now();
    $user->save();

    $this->info($existe ? 'Compte existant : accès admin et mot de passe mis à jour.' : 'Compte admin créé.');
    $this->line('  Numéro       : '.$user->phone_code.' '.$user->phone_number);
    $this->line('  Mot de passe : celui que vous venez de saisir');
    $this->line('  Connexion    : '.config('app.url').'/connexion');
    $this->line('  Admin        : '.config('app.url').'/admin');

    return 0;
})->purpose('Créer (ou promouvoir) un compte admin avec un numéro et un mot de passe');
// eMess : voir quels modèles existent sur le compte, et tester un envoi sans passer par le site
Artisan::command('emess:modeles', function () {
    $c = config('messaging.emess');
    if (blank($c['app_id']) || blank($c['secret_key'])) {
        $this->error('EMESS_APP_ID / EMESS_SECRET_KEY manquants dans .env');

        return 1;
    }
    $base = rtrim((string) $c['url'], '/');
    $auth = \Illuminate\Support\Facades\Http::acceptJson()->asJson()->timeout(20)
        ->post($base.'/auth/token', ['appId' => $c['app_id'], 'secretKey' => $c['secret_key']]);
    if (! $auth->successful()) {
        $this->error('Connexion eMess refusée (HTTP '.$auth->status().') : '.mb_substr((string) $auth->body(), 0, 200));

        return 1;
    }
    $token = (string) $auth->json('token');
    $this->info('Connecté à eMess — application '.$c['app_id']);
    $this->line('');
    $this->line('Modèles envoyés par Sosa :');
    $this->line('  code de vérification : '.\App\Services\Messaging\SmsTemplates::slug('code'));
    $this->line('  message d\'essai     : '.\App\Services\Messaging\SmsTemplates::slug('test'));
    $this->line('');
    $this->line('Ce que l\'API répond aux adresses de liste (pour voir les modèles du compte) :');
    foreach (['templates', 'sms/templates', 'template/list'] as $path) {
        $res = \Illuminate\Support\Facades\Http::acceptJson()->withToken($token)->timeout(20)->get($base.'/'.$path);
        $this->line('  GET /'.$path.' → HTTP '.$res->status().'  '.mb_substr((string) $res->body(), 0, 300));
    }

    return 0;
})->purpose('eMess : voir les modèles du compte et ceux qu\'utilise Sosa');

Artisan::command('emess:essai {numero}', function (string $numero) {
    $digits = (string) preg_replace('/\D/', '', $numero);
    $phone = str_starts_with(trim($numero), '+') ? '+'.$digits : '+243'.ltrim($digits, '0');
    $text = \App\Services\Messaging\SmsTemplates::render('test', []) ?? 'Message d\'essai Sosa.';
    $this->line('Envoi vers '.$phone.' avec le modèle « '.\App\Services\Messaging\SmsTemplates::slug('test').' »');
    try {
        $ref = app(\App\Services\Messaging\Messenger::class)->channel('sms')->send($phone, $text, 'test');
        $this->info('Envoyé — '.$ref);

        return 0;
    } catch (\Throwable $e) {
        $this->error('Échec : '.$e->getMessage());

        return 1;
    }
})->purpose('eMess : envoyer un SMS d\'essai (modèle approuvé) à un numéro');

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
