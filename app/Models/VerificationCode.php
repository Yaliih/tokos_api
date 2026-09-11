<?php
// app/Models/VerificationCode.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone_code',
        'phone_number',
        'code',
        'expires_at',
        'is_used',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    /**
     * Générer un code unique
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('code', $code)->where('is_used', false)->exists());

        return $code;
    }

    /**
     * Vérifier si le code est valide
     */
    public function isValid(): bool
    {
        return !$this->is_used && $this->expires_at->isFuture();
    }

    /**
     * Marquer le code comme utilisé
     */
    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }

    /**
     * Incrémenter le compteur de tentatives
     */
    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    /**
     * Nettoyer les codes expirés
     */
    public static function cleanExpired(): void
    {
        self::where('expires_at', '<', now())
            ->orWhere('is_used', true)
            ->delete();
    }
}