<?php

namespace App\Filament\Resources\Certifications;

use App\Filament\Resources\Certifications\Pages\ListCertifications;
use App\Models\VerificationRequest;
use App\Services\CertificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Demandes de certification : on regarde les pièces, on accorde ou on refuse. */
class CertificationResource extends Resource
{
    protected static ?string $model = VerificationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Modération';

    protected static ?string $modelLabel = 'demande de certification';

    protected static ?string $pluralModelLabel = 'certifications';

    protected static ?string $navigationLabel = 'Certifications';

    protected static ?int $navigationSort = 2;

    public const REJECT_REASONS = [
        'La photo de la pièce n\'est pas lisible.',
        'Le selfie ne montre pas clairement ton visage, ta pièce et le code.',
        'Le nom ne correspond pas à la pièce.',
        'Le justificatif ne prouve pas ton statut.',
        'Le document de l\'organisation manque ou n\'est pas officiel.',
    ];

    public static function getNavigationBadge(): ?string
    {
        $count = VerificationRequest::where('status', 'pending')->count();

        return $count ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $file = fn (string $field, string $label) => ImageEntry::make($field)->label($label)
            ->state(fn (VerificationRequest $record): ?string => $record->fileUrl($field))
            ->imageHeight(220)->extraImgAttributes(['style' => 'max-width:100%;object-fit:contain;border-radius:10px'])->placeholder('Non fourni ou déjà supprimé')
            ->url(fn (VerificationRequest $record): ?string => $record->fileUrl($field), shouldOpenInNewTab: true);

        return $schema->columns(1)->components([
            Section::make()->schema([
                TextEntry::make('subject')->label('Pour')->state(fn (VerificationRequest $record): string => ($record->forSpace() ? 'Espace : ' : '').$record->subjectName()),
                TextEntry::make('full_name')->label('Nom déclaré'),
                TextEntry::make('category')->label('Catégorie')->state(fn (VerificationRequest $record): string => $record->categoryLabel()),
                TextEntry::make('document_type')->label('Pièce')->state(fn (VerificationRequest $record): string => config('certification.documents.'.$record->document_type, (string) $record->document_type)),
                TextEntry::make('code')->label('Code attendu sur le selfie')->state(fn (VerificationRequest $record): string => CertificationService::selfieCode($record->user)),
                TextEntry::make('phone')->label('Numéro vérifié')->state(fn (VerificationRequest $record): string => $record->user->phone_code.' '.$record->user->phone_number.($record->user->phone_verified_at ? '' : ' (non vérifié)')),
                TextEntry::make('links')->label('Liens')->state(fn (VerificationRequest $record): string => implode("\n", $record->links ?? []) ?: 'Aucun'),
                TextEntry::make('note')->label('Mot de la personne')->placeholder('Aucun'),
            ])->columns(2),
            Grid::make(3)->schema([
                $file('document_path', 'Pièce d\'identité'),
                $file('selfie_path', 'Selfie avec la pièce'),
                $file('proof_path', 'Justificatif'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'space']))
            ->columns([
                TextColumn::make('full_name')->label('Demandeur')->searchable()
                    ->description(fn (VerificationRequest $record): string => $record->forSpace() ? 'Espace '.$record->space->name : '@'.$record->user->username),
                TextColumn::make('category')->label('Catégorie')->formatStateUsing(fn (VerificationRequest $record): string => $record->categoryLabel()),
                TextColumn::make('status')->label('État')->badge()->formatStateUsing(fn (string $state): string => VerificationRequest::STATUS[$state] ?? $state)
                    ->color(fn (string $state): string => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('admin_note')->label('Note')->limit(40)->placeholder(''),
                TextColumn::make('created_at')->label('Reçue')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label('État')->options(VerificationRequest::STATUS)->default('pending')])
            ->recordActions([
                ViewAction::make()->label('Examiner')->modalWidth('5xl')->modalHeading(fn (VerificationRequest $record): string => 'Certification : '.$record->subjectName())
                    ->extraModalFooterActions(fn (VerificationRequest $record): array => $record->status === 'pending' ? [
                        self::approveAction()->cancelParentActions(),
                        self::rejectAction()->cancelParentActions(),
                    ] : []),
                self::approveAction()->visible(fn (VerificationRequest $record): bool => $record->status === 'pending'),
                self::rejectAction()->visible(fn (VerificationRequest $record): bool => $record->status === 'pending'),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')->label('Certifier')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->requiresConfirmation()->modalDescription('Le badge apparaît tout de suite et la personne est prévenue.')
            ->action(fn (VerificationRequest $record) => app(CertificationService::class)->approve($record, auth()->user()));
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')->label('Refuser')->icon(Heroicon::OutlinedXMark)->color('danger')
            ->schema([
                Select::make('preset')->label('Motif')->options(array_combine(self::REJECT_REASONS, self::REJECT_REASONS))->native(false)->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('reason', $state)),
                Textarea::make('reason')->label('Message envoyé à la personne')->required()->maxLength(300)->rows(2),
            ])
            ->action(fn (VerificationRequest $record, array $data) => app(CertificationService::class)->reject($record, auth()->user(), $data['reason']));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListCertifications::route('/')];
    }
}
