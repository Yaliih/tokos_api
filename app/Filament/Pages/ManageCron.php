<?php

namespace App\Filament\Pages;

use App\Support\CronRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Tâches automatiques avec cron-job.org : une adresse secrète à appeler chaque minute.
 *
 * @property-read Schema $form
 */
class ManageCron extends Page
{
    protected string $view = 'filament.pages.manage-features';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Tâches automatiques';

    protected static ?string $title = 'Tâches automatiques';

    protected static ?string $slug = 'taches-automatiques';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public static function status(): array
    {
        $s = CronRunner::settings();
        if (empty($s['last_run_at'])) {
            return ['bad', 'Jamais appelée pour le moment. Les duels ne se terminent pas tout seuls tant que ce n\'est pas branché.'];
        }
        $at = Carbon::parse($s['last_run_at']);
        $ago = $at->locale('fr')->diffForHumans();
        $line = 'Dernier passage '.$ago.' ('.($s['last_ms'] ?? 0).' ms, '.($s['last_jobs'] ?? 0).' tâche(s) traitée(s), '.($s['runs'] ?? 0).' passages en tout).';
        if (! empty($s['last_error'])) {
            return ['bad', $line.' Erreur : '.$s['last_error']];
        }

        return [$at->lt(now()->subMinutes(5)) ? 'bad' : 'ok', $at->lt(now()->subMinutes(5)) ? $line.' C\'est trop ancien : vérifie cron-job.org.' : $line];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('État')
                ->schema([
                    Text::make(fn () => (self::status()[0] === 'ok' ? 'Tout marche. ' : 'À vérifier. ').self::status()[1]),
                    Text::make(fn () => CronRunner::pendingJobs().' tâche(s) en attente dans la file.'),
                    Actions::make([
                        Action::make('runNow')->label('Lancer maintenant')->icon(Heroicon::OutlinedPlay)->action('runNow'),
                    ]),
                ]),
            Section::make('Brancher cron-job.org')
                ->description('Gratuit. Une seule tâche suffit : elle lance « schedule:run » puis traite la file d\'attente (« queue:work »).')
                ->schema([
                    Text::make('Adresse à appeler (garde-la secrète) :'),
                    Text::make(fn () => CronRunner::url())->copyable()->fontFamily('mono'),
                    Text::make('1. Crée un compte sur cron-job.org puis « Create cronjob ».'),
                    Text::make('2. URL : colle l\'adresse ci-dessus. Exécution : « Every minute ».'),
                    Text::make('3. Dans « Advanced » : méthode GET, délai (timeout) 30 secondes. Enregistre.'),
                    Text::make('4. Reviens ici : le « Dernier passage » doit dater de moins d\'une minute.'),
                    Actions::make([
                        Action::make('regen')->label('Changer l\'adresse secrète')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                            ->requiresConfirmation()->modalDescription('L\'ancienne adresse ne marchera plus : il faudra coller la nouvelle dans cron-job.org.')
                            ->action('regenerate'),
                    ]),
                ]),
        ])->statePath('data');
    }

    public function runNow(): void
    {
        $r = CronRunner::run();
        Notification::make()->{$r['ran'] ? 'success' : 'warning'}()
            ->title($r['ran'] ? 'Fait en '.$r['ms'].' ms' : 'Un passage est déjà en cours')
            ->body($r['ran'] ? $r['jobs'].' tâche(s) traitée(s).' : 'Réessaie dans une minute.')->send();
    }

    public function regenerate(): void
    {
        CronRunner::regenerate();
        Notification::make()->success()->title('Nouvelle adresse créée')->body('Colle-la dans cron-job.org à la place de l\'ancienne.')->send();
    }
}
