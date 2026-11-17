<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Demande de certification d'une personne ou d'un Espace, examinée dans l'admin. */
class VerificationRequest extends Model
{
    public const STATUS = ['pending' => 'En attente', 'approved' => 'Certifié', 'rejected' => 'Refusé'];

    protected $fillable = ['user_id', 'space_id', 'category', 'full_name', 'document_type', 'document_path', 'selfie_path', 'proof_path', 'links', 'note', 'status', 'admin_note', 'reviewed_by', 'reviewed_at'];

    protected $casts = ['links' => 'array', 'reviewed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function space()
    {
        return $this->belongsTo(Space::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function forSpace(): bool
    {
        return $this->space_id !== null;
    }

    public function subjectName(): string
    {
        return $this->space ? $this->space->name : trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? ''));
    }

    public function categoryLabel(): string
    {
        return config('certification.categories.'.($this->forSpace() ? 'space' : 'user').'.'.$this->category, $this->category);
    }

    /** Lien temporaire (10 min) vers une pièce privée, pour l'admin. */
    public function fileUrl(string $field): ?string
    {
        $path = $this->{$field};

        return $path && Storage::disk('local')->exists($path) ? Storage::disk('local')->temporaryUrl($path, now()->addMinutes(10)) : null;
    }

    public function deleteFiles(): void
    {
        foreach (['document_path', 'selfie_path', 'proof_path'] as $f) {
            if ($this->{$f}) {
                Storage::disk('local')->delete($this->{$f});
            }
        }
        $this->forceFill(['document_path' => null, 'selfie_path' => null, 'proof_path' => null])->saveQuietly();
    }
}
