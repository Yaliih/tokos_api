<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateVote extends Model
{
    use HasFactory;

    protected $table = 'candidate_votes';

    protected $fillable = [
        'candidate_id',
        'user_id',
        'phone_number',
        'phone_code',
        'value',
        'ip_address',
        'is_anonymous',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'value' => 'integer',
    ];

    /**
     * Relation avec le candidat
     */
    public function candidate()
    {
        return $this->belongsTo(ContestCandidate::class, 'candidate_id');
    }

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifier si un utilisateur peut voter pour un candidat
     */
    public static function canVote($candidateId, $identifier): bool
    {
        $query = self::where('candidate_id', $candidateId);

        if (is_numeric($identifier)) {
            $query->where('user_id', $identifier);
        } else {
            $query->where('phone_number', $identifier);
        }

        return !$query->exists();
    }

    /**
     * Créer un vote anonyme
     */
    public static function createAnonymous($candidateId, $phoneNumber, $phoneCode, $value = 1, $ip = null)
    {
        return self::create([
            'candidate_id' => $candidateId,
            'phone_number' => $phoneNumber,
            'phone_code' => $phoneCode,
            'value' => $value,
            'ip_address' => $ip,
            'is_anonymous' => true,
        ]);
    }

    /**
     * Créer un vote utilisateur
     */
    public static function createUser($candidateId, $userId, $value = 1, $ip = null)
    {
        return self::create([
            'candidate_id' => $candidateId,
            'user_id' => $userId,
            'value' => $value,
            'ip_address' => $ip,
            'is_anonymous' => false,
        ]);
    }

    /**
     * Vérifier si l'utilisateur a déjà voté pour ce candidat
     */
    public static function hasUserVoted($candidateId, $userId): bool
    {
        return self::where('candidate_id', $candidateId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Compter les votes pour un candidat
     */
    public static function countVotes($candidateId): int
    {
        return self::where('candidate_id', $candidateId)->sum('value') ?? 0;
    }
}