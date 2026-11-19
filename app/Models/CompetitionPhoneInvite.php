<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Défi envoyé à un numéro sans compte Sosa (SMS ou WhatsApp).
 */
class CompetitionPhoneInvite extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sms_sent_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $i) {
            $i->token ??= Str::random(32);
        });
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function url(): string
    {
        return url('/i/'.$this->token);
    }

    public function fullPhone(): string
    {
        return $this->phone_code.$this->phone_number;
    }

    /** Lien WhatsApp avec le message déjà écrit. */
    public function whatsappUrl(): string
    {
        $digits = preg_replace('/\D+/', '', $this->fullPhone());

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($this->text());
    }

    /** Variables du modèle SMS approuvé (voir App\Services\Messaging\SmsTemplates). */
    public function smsContext(): array
    {
        $who = trim(($this->inviter?->first_name ?? '').' '.($this->inviter?->last_name ?? '')) ?: 'Quelqu\'un';

        return [
            'app_name' => (string) config('messaging.app_name', 'Sosa'),
            'name' => mb_substr($who, 0, 40),
            'title' => mb_substr((string) ($this->competition?->title ?? 'un duel'), 0, 40),
            'lien' => mb_substr($this->url(), 0, 64),
        ];
    }
    public function text(): string
    {
        $who = trim(($this->inviter?->first_name ?? '').' '.($this->inviter?->last_name ?? '')) ?: 'Quelqu\'un';

        return $who.' te défie sur Sosa : « '.($this->competition?->title ?? 'un duel').' ». '
            .($this->message ? $this->message.' ' : '')
            .'Relève le défi : '.$this->url();
    }
}
