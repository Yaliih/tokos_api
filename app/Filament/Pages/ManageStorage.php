<?php

namespace App\Filament\Pages;

use App\Support\MediaStorage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Crypt;
use UnitEnum;

/**
 * Stockage des médias : le serveur au début, Cloudflare R2 quand on veut, avec copie des fichiers existants.
 *
 * @property-read Schema $form
 */
class ManageStorage extends Page
{
    protected string $view = 'filament.pages.manage-storage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Stockage des médias';

    protected static ?string $title = 'Stockage des médias';

    protected static ?string $slug = 'stockage';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public function mount(): void
    {
        $r2 = config('filesystems.disks.r2');
        $this->form->fill([
            'endpoint' => $r2['endpoint'] ?? '', 'bucket' => $r2['bucket'] ?? '', 'key' => $r2['key'] ?? '',
            'secret' => '', 'url' => $r2['url'] ?? '',
        ]);
    }

    public function copyRunning(): bool
    {
        return ! empty(MediaStorage::settings()['copy']['running']);
    }

    public static function copyLine(): string
    {
        $c = MediaStorage::settings()['copy'] ?? [];
        if (empty($c['direction'])) {
            return 'Aucune copie lancée pour le moment.';
        }
        $dir = $c['direction'] === 'to_local' ? 'R2 → serveur' : 'serveur → R2';
        $done = (int) ($c['cursor'] ?? 0);
        $total = max(0, (int) ($c['total'] ?? 0));
        $pct = $total ? (int) floor($done / $total * 100) : 100;
        $line = ($c['running'] ? 'Copie en cours ('.$dir.') : ' : 'Dernière copie ('.$dir.') : ').$done.' / '.$total.' fichiers vérifiés ('.$pct.' %), '
            .(int) ($c['copied'] ?? 0).' copié(s)'.((int) ($c['errors'] ?? 0) ? ', '.$c['errors'].' erreur(s)' : '').'.';
        if (! empty($c['last_error'])) {
            $line .= ' Dernière erreur : '.$c['last_error'];
        }

        return $line;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stockage actuel')
                ->schema([
                    Text::make(fn () => MediaStorage::current() === 'r2'
                        ? 'Les nouveaux fichiers vont chez Cloudflare R2.'.(MediaStorage::directUrls() ? ' Tous les fichiers y sont : liens directs vers R2.' : ' Les anciens restent servis par le serveur tant qu\'ils n\'ont pas été copiés.')
                        : 'Les nouveaux fichiers sont rangés sur le serveur (storage/app/public).'),
                    Text::make(fn () => MediaStorage::adapterInstalled() ? (MediaStorage::configured() ? 'R2 : clés enregistrées.' : 'R2 : clés à remplir plus bas.') : 'R2 : module absent. Sur le serveur, lance une fois : composer require league/flysystem-aws-s3-v3'),
                    Actions::make([
                        Action::make('useR2')->label('Passer à Cloudflare R2')->icon(Heroicon::OutlinedCloudArrowUp)
                            ->visible(fn () => MediaStorage::current() !== 'r2')->disabled(fn () => ! MediaStorage::ready())
                            ->requiresConfirmation()->modalDescription('Les nouveaux fichiers iront chez R2. Les anciens restent sur le serveur et continuent de s\'afficher. Pense à lancer la copie pour tout envoyer chez R2.')
                            ->action('useR2'),
                        Action::make('useLocal')->label('Revenir au serveur')->icon(Heroicon::OutlinedServer)->color('gray')
                            ->visible(fn () => MediaStorage::current() === 'r2')
                            ->requiresConfirmation()->modalDescription('Les nouveaux fichiers seront rangés sur le serveur. Ceux qui sont seulement chez R2 restent lisibles ; tu peux aussi les rapatrier.')
                            ->action('useLocal'),
                    ]),
                ]),

            Form::make([
                Section::make('Cloudflare R2')
                    ->description('Dans Cloudflare : R2 → crée un bucket, puis « Manage R2 API Tokens » (droits lecture et écriture). Branche un domaine public sur le bucket (ex. media.sosa.social).')
                    ->schema([
                        TextInput::make('endpoint')->label('Point d\'accès (S3 API)')->placeholder('https://<id-du-compte>.r2.cloudflarestorage.com')->url(),
                        TextInput::make('bucket')->label('Nom du bucket')->placeholder('tokos-media'),
                        TextInput::make('key')->label('Access Key ID'),
                        TextInput::make('secret')->label('Secret Access Key')->password()->revealable()
                            ->placeholder(fn () => filled(config('filesystems.disks.r2.secret')) ? 'Déjà enregistrée (laisser vide pour la garder)' : ''),
                        TextInput::make('url')->label('Adresse publique des fichiers')->placeholder('https://media.sosa.social')->url()
                            ->helperText('Le domaine branché sur le bucket, ou l\'adresse r2.dev publique.'),
                    ])->columns(2),
            ])
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('save')->label('Enregistrer')->submit('save'),
                    Action::make('test')->label('Tester la connexion')->icon(Heroicon::OutlinedSignal)->color('gray')->action('testR2'),
                ])]),

            Section::make('Copier les fichiers existants')
                ->description('Rien n\'est supprimé : les fichiers sont copiés, l\'original reste en place. La copie avance quand cette page est ouverte, et aussi en arrière-plan grâce aux tâches automatiques.')
                ->schema([
                    Text::make(fn () => self::copyLine()),
                    Actions::make([
                        Action::make('copyToR2')->label('Copier serveur → R2')->icon(Heroicon::OutlinedArrowUpTray)
                            ->disabled(fn () => ! MediaStorage::ready() || $this->copyRunning())->action('copyToR2'),
                        Action::make('copyToLocal')->label('Rapatrier R2 → serveur')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                            ->disabled(fn () => ! MediaStorage::ready() || $this->copyRunning())->action('copyToLocal'),
                        Action::make('stop')->label('Arrêter la copie')->color('danger')->visible(fn () => $this->copyRunning())->action('stopCopy'),
                    ]),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $s = $this->form->getState();
        $r2 = ['endpoint' => rtrim(trim((string) ($s['endpoint'] ?? '')), '/'), 'bucket' => trim((string) ($s['bucket'] ?? '')),
            'key' => trim((string) ($s['key'] ?? '')), 'url' => rtrim(trim((string) ($s['url'] ?? '')), '/')];
        if (filled($s['secret'] ?? null)) {
            $r2['secret'] = Crypt::encryptString(trim($s['secret']));
        }
        MediaStorage::save(['r2' => $r2]);
        $this->data['secret'] = '';
        Notification::make()->success()->title('Réglages R2 enregistrés')->body('Clique sur « Tester la connexion » pour vérifier.')->send();
    }

    public function testR2(): void
    {
        $r = MediaStorage::test();
        Notification::make()->{$r['ok'] ? 'success' : 'danger'}()->title($r['ok'] ? 'R2 fonctionne' : 'R2 ne répond pas')->body($r['message'])->send();
    }

    public function useR2(): void
    {
        $t = MediaStorage::test();
        if (! $t['ok']) {
            Notification::make()->danger()->title('Impossible de passer à R2')->body($t['message'])->send();

            return;
        }
        MediaStorage::switchTo('r2');
        Notification::make()->success()->title('Nouveaux fichiers : chez R2')->body('Lance maintenant « Copier serveur → R2 » pour les fichiers existants.')->send();
    }

    public function useLocal(): void
    {
        MediaStorage::switchTo('public');
        Notification::make()->success()->title('Nouveaux fichiers : sur le serveur')->send();
    }

    public function copyToR2(): void
    {
        MediaStorage::startCopy('to_r2');
        $this->tick();
    }

    public function copyToLocal(): void
    {
        MediaStorage::startCopy('to_local');
        $this->tick();
    }

    public function stopCopy(): void
    {
        MediaStorage::stopCopy();
        Notification::make()->title('Copie arrêtée')->body('Tu peux la relancer : les fichiers déjà copiés ne sont pas recopiés.')->send();
    }

    /** Appelé toutes les 4 secondes pendant la copie. */
    public function tick(): void
    {
        $before = $this->copyRunning();
        MediaStorage::step(6);
        if ($before && ! $this->copyRunning()) {
            Notification::make()->success()->title('Copie terminée')->body(self::copyLine())->send();
        }
    }
}
