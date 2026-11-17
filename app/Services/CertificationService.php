<?php

namespace App\Services;

use App\Models\Space;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Social\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Demandes de certification : envoi des pièces, décision de l'admin, badge. */
class CertificationService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Code à écrire sur une feuille et à tenir sur le selfie (le même pour une personne, tant qu'elle n'a pas envoyé). */
    public static function selfieCode(User $u): string
    {
        return 'SOSA-'.str_pad((string) (crc32($u->id.'|'.$u->created_at.'|'.config('app.key')) % 10000), 4, '0', STR_PAD_LEFT);
    }

    public function submit(User $user, array $data, ?Space $space = null): VerificationRequest
    {
        $target = $space ? VerificationRequest::where('space_id', $space->id) : VerificationRequest::where('user_id', $user->id)->whereNull('space_id');
        if ((clone $target)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['category' => 'Une demande est déjà en cours d\'examen.']);
        }
        if ($space ? $space->is_verified : $user->verified_at) {
            throw ValidationException::withMessages(['category' => 'Déjà certifié.']);
        }
        $dir = 'certification/'.$user->id.'/'.now()->format('YmdHis');
        $store = fn (?UploadedFile $f, string $name) => $f ? $f->storeAs($dir, $name.'.'.($f->guessExtension() ?: 'jpg'), 'local') : null;

        return VerificationRequest::create([
            'user_id' => $user->id,
            'space_id' => $space?->id,
            'category' => $data['category'],
            'full_name' => $data['full_name'],
            'document_type' => $data['document_type'],
            'document_path' => $store($data['document'] ?? null, 'piece'),
            'selfie_path' => $store($data['selfie'] ?? null, 'selfie'),
            'proof_path' => $store($data['proof'] ?? null, 'justificatif'),
            'links' => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['links'] ?? ''))))),
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function approve(VerificationRequest $r, User $admin, ?string $note = null): void
    {
        DB::transaction(function () use ($r, $admin, $note) {
            $r->update(['status' => 'approved', 'admin_note' => $note, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            if ($r->space) {
                $r->space->forceFill(['is_verified' => true])->save();
            } else {
                $r->user->forceFill(['verified_at' => now()])->save();
            }
        });
        $this->notifications->send($r->user, 'verification.approved', 'Certification accordée',
            ($r->space ? $r->space->name : 'Ton profil').' porte maintenant le badge certifié.', $r->space ? '/espaces/'.$r->space->slug : '/@'.$r->user->username);
    }

    public function reject(VerificationRequest $r, User $admin, string $reason): void
    {
        $r->update(['status' => 'rejected', 'admin_note' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        $this->notifications->send($r->user, 'verification.rejected', 'Certification refusée', $reason.' Tu peux refaire une demande.', '/parametres/certification');
    }

    /** Retirer le badge. */
    public function revoke(User|Space $subject): void
    {
        $subject instanceof Space ? $subject->forceFill(['is_verified' => false])->save() : $subject->forceFill(['verified_at' => null])->save();
    }

    /** Supprime les pièces des demandes traitées depuis plus de N jours. */
    public function purge(): int
    {
        $n = 0;
        VerificationRequest::whereIn('status', ['approved', 'rejected'])
            ->where('reviewed_at', '<', now()->subDays((int) config('certification.keep_documents_days', 30)))
            ->where(fn ($q) => $q->whereNotNull('document_path')->orWhereNotNull('selfie_path')->orWhereNotNull('proof_path'))
            ->each(function (VerificationRequest $r) use (&$n) {
                $r->deleteFiles();
                $n++;
            });

        return $n;
    }
}
